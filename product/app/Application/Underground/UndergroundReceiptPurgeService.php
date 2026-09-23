<?php

namespace App\Application\Underground;

use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Models\UndergroundProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** Manual retention of verified prefixes; never aggregates or awards anything. */
final class UndergroundReceiptPurgeService
{
    public function __construct(private UndergroundReceiptRollupService $rollups) {}

    /** @return array<string, mixed> */
    public function purge(int $profileId, string $stream, CarbonInterface $cutoff, int $batchSize = 500, bool $apply = false): array
    {
        if ($profileId < 1 || $batchSize < 1 || $batchSize > 1_000
            || $cutoff->isAfter(CarbonImmutable::now()->subDays(30))) {
            throw new InvalidArgumentException('Purge requires a profile, batch 1-1000 and a cutoff at least 30 days ago.');
        }
        [$table, $finishedColumn] = $this->rollups->source($stream);
        $this->rollups->assertSourceSequence($table);

        return DB::transaction(function () use ($profileId, $stream, $cutoff, $batchSize, $apply, $table, $finishedColumn): array {
            // Same serialization point as receipt writers and aggregation. The
            // preview takes only a shared lock and never creates a checkpoint.
            $profileQuery = UndergroundProfile::query()->whereKey($profileId);
            ($apply ? $profileQuery->lockForUpdate() : $profileQuery->sharedLock())->firstOrFail(['id']);
            $identity = ['underground_profile_id' => $profileId, 'stream' => $stream];
            $checkpoint = DB::table('underground_receipt_rollups')->where($identity)->first();
            if ($checkpoint !== null && (int) $checkpoint->aggregation_version !== UndergroundReceiptRollupService::VERSION) {
                throw new RuntimeException('Unsupported receipt aggregation version.');
            }
            $from = (int) ($checkpoint->deleted_through_id ?? 0);
            $verified = (int) ($checkpoint->verified_through_id ?? 0);
            $query = DB::table($table)->where('underground_profile_id', $profileId)->where('id', '>', $from);
            $columns = ['id', $finishedColumn];
            if ($stream === 'battle') {
                $columns = [...$columns, 'activity_type', 'activity_key', 'compaction_version', 'trial_run_key', 'underground_party_id'];
            } elseif ($stream === 'intro_request') {
                $columns[] = 'operation';
            }
            $candidates = (clone $query)->orderBy('id')->limit($batchSize)->get($columns);
            $intro = DB::table('underground_intro_progress')->where('underground_profile_id', $profileId)->first();
            $activeRun = DB::table('underground_trial_runs')->where('underground_profile_id', $profileId)->where('status', 'active')->value('run_key');
            $ids = [];
            $partyIds = [];
            $to = $from;
            $reason = $candidates->count() === $batchSize ? 'batch_limit' : 'no_more_receipts';
            $stoppedAt = null;
            $oldest = null;
            foreach ($candidates as $candidate) {
                $blocker = $this->rollups->preparationBlocker($stream, $candidate, $cutoff);
                if ($blocker === null && (int) $candidate->id > $verified) {
                    $blocker = 'unverified_receipt';
                }
                if ($blocker === null) {
                    $blocker = $this->durabilityBlocker($profileId, $stream, $candidate, $intro, $activeRun, $checkpoint);
                }
                if ($blocker !== null) {
                    $reason = $blocker;
                    $stoppedAt = (int) $candidate->id;
                    break;
                }
                $to = (int) $candidate->id;
                $ids[] = $to;
                if ($stream === 'battle' && $candidate->underground_party_id !== null) {
                    $partyIds[] = (int) $candidate->underground_party_id;
                }
                $finished = CarbonImmutable::parse($candidate->{$finishedColumn});
                $oldest = $oldest === null || $finished->isBefore($oldest) ? $finished : $oldest;
            }
            $count = count($ids);
            $oldReceipts = $candidates->filter(static fn (stdClass $row): bool => $row->{$finishedColumn} !== null
                && CarbonImmutable::parse($row->{$finishedColumn})->isBefore($cutoff))->count();
            if ($apply && $count > 0) {
                if ($stream === 'battle') {
                    // Participation was made durable at settlement/backfill by
                    // D2. No owner profile locks, recounts or rewards here.
                    DB::table('secretary_lending_participations')->whereIn('underground_battle_id', $ids)->delete();
                    DB::table('underground_battle_image_references')->whereIn('underground_battle_id', $ids)->delete();
                }
                $deleted = (clone $query)->whereIn('id', $ids)->delete();
                if ($deleted !== $count) {
                    throw new RuntimeException('Receipt delete count did not match its locked prefix.');
                }
                if ($partyIds !== []) {
                    DB::table('underground_party_members')->whereIn('underground_party_id', $partyIds)->delete();
                    DB::table('underground_parties')->whereIn('id', $partyIds)->delete();
                }
                $deletedCount = (int) $checkpoint->deleted_receipt_count + $count;
                $updated = DB::table('underground_receipt_rollups')->where($identity)
                    ->where('deleted_through_id', $from)->where('verified_through_id', $verified)->update([
                        'deleted_through_id' => $to, 'deleted_receipt_count' => $deletedCount,
                        'last_deletion' => json_encode([
                            'from_exclusive' => $from, 'through_inclusive' => $to,
                            'receipts' => $deleted, 'cutoff' => $cutoff->toIso8601String(), 'at' => now()->toIso8601String(),
                        ], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1 || (clone $query)->where('id', '<=', $to)->exists()
                    || (int) DB::table('underground_receipt_rollups')->where($identity)->value('deleted_through_id') !== $to) {
                    throw new RuntimeException('Receipt deletion checkpoint verification failed.');
                }
            }

            return [
                'profile_id' => $profileId, 'stream' => $stream, 'applied' => $apply && $count > 0,
                'from_id' => $from, 'candidate_through_id' => $to, 'verified_through_id' => $verified,
                'deleted_through_id' => $apply ? $to : $from, 'candidates' => $count,
                'old_receipts_in_scan' => $oldReceipts, 'scan_limit' => $batchSize,
                'oldest_candidate_at' => $oldest?->toIso8601String(),
                'oldest_scanned_at' => $candidates->min($finishedColumn),
                'stop_reason' => $reason, 'stopped_at_id' => $stoppedAt,
                'message' => $oldReceipts > 0 ? '古いログが溜まっています' : null,
            ];
        }, 1);
    }

    private function durabilityBlocker(int $profileId, string $stream, stdClass $receipt, ?stdClass $intro, ?string $activeRun, ?stdClass $checkpoint): ?string
    {
        if ($stream === 'intro_request') {
            if ($intro !== null && $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN) {
                return 'active_story';
            }
            if ($receipt->operation === 'growth_path' && ($intro->initial_growth_path_key ?? null) === null) {
                return 'initial_growth_fact_missing';
            }

            return null;
        }
        if ($stream !== 'battle') {
            return null;
        }
        if ($checkpoint?->lifetime_statistics === null) {
            return 'lifetime_statistics_pending';
        }
        if ($receipt->trial_run_key !== null && $receipt->trial_run_key === $activeRun) {
            return 'active_trial';
        }
        if ($intro !== null && $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            && in_array((int) $receipt->id, [(int) $intro->tutorial_battle_id, (int) $intro->scripted_loss_battle_id], true)) {
            return 'active_story';
        }
        if ($intro !== null && (int) $intro->tutorial_battle_id === (int) $receipt->id && $intro->tutorial_encounter_key === null) {
            return 'tutorial_fact_missing';
        }
        if (DB::table('underground_battle_logs')->where('underground_battle_id', $receipt->id)->where('expires_at', '>', now())->exists()
            || DB::table('underground_battle_image_references')->where('underground_battle_id', $receipt->id)->where('retained_until', '>', now())->exists()) {
            return 'retention_pin';
        }
        if ($receipt->activity_type === 'trial') {
            $progress = DB::table('underground_trial_progress')->where('underground_profile_id', $profileId)
                ->where('trial_key', $receipt->activity_key)->first();
            if ($progress === null || $progress->first_challenged_at === null) {
                return 'trial_first_fact_missing';
            }
            $snapshot = DB::table('underground_battles')->where('id', $receipt->id)
                ->selectRaw("snapshot->'challenge_intro' AS challenge_intro, snapshot->'first_clear_story' AS first_clear_story")->first();
            if (($snapshot->challenge_intro !== null && $snapshot->challenge_intro !== 'null' && $progress->first_challenge_intro === null)
                || ($snapshot->first_clear_story !== null && $snapshot->first_clear_story !== 'null' && $progress->first_clear_story === null)) {
                return 'trial_story_fact_missing';
            }
        }

        return null;
    }
}
