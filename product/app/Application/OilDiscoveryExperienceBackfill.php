<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\SecretarySurfaceState;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One bounded, explicit historical award. No automatic migration or Turn backfill. */
final class OilDiscoveryExperienceBackfill
{
    public function __construct(
        private readonly WorldMutationLock $lock,
        private readonly SecretarySkillCatalog $catalog,
        private readonly SecretarySkillProgression $progression,
    ) {}

    /** @return array<string, mixed> */
    public function preview(World $world): array
    {
        $boundary = DB::table('oil_discovery_backfills')->where('world_id', $world->id)->first();
        if ($boundary === null) {
            throw new RuntimeException('This World has no pre-v30 oil discovery boundary.');
        }
        $candidates = [];
        $skipped = [];
        $excluded = [];
        $awards = [];
        $seen = [];
        $events = DB::table('audit_events')->where('world_id', $world->id)
            ->where('event_type', 'command.seabed_oil_search')
            ->whereRaw("metadata->'found' = 'true'::jsonb")
            ->where('id', '<=', $boundary->cutoff_audit_id)
            ->where(fn ($query) => $query->where('turn', '<=', $boundary->cutoff_turn)->orWhereNull('turn'))
            ->orderBy('id')->get();
        foreach ($events as $event) {
            $metadata = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR);
            $runId = $metadata['turn_run_id'] ?? null;
            $queueId = $metadata['queue_item_id'] ?? null;
            $nationId = $metadata['nation_id'] ?? null;
            $reason = null;
            $secretaryId = null;
            if ($event->turn === null || ! is_int($runId) || $runId < 1 || ! is_int($queueId) || $queueId < 1
                || ! is_int($nationId) || $nationId < 1 || (int) $event->nation_id !== $nationId) {
                $reason = 'missing_or_conflicting_identity';
            } else {
                $run = TurnRun::query()->with('rulesetVersion')->find($runId);
                if ($run === null || $run->world_id !== (int) $world->id || $run->target_turn !== (int) $event->turn) {
                    $reason = 'missing_or_conflicting_turn_run';
                } elseif ($run->status !== TurnRun::STATUS_COMPLETED || $run->is_dry_run) {
                    $reason = 'not_a_completed_production_turn';
                } elseif ($run->rulesetVersion->version >= 30) {
                    $reason = 'online_xp_ruleset';
                } else {
                    $nation = Nation::query()->where('world_id', $world->id)->find($nationId);
                    $userIds = $nation === null ? [] : DB::table('nation_memberships')
                        ->where('world_id', $world->id)->where('nation_id', $nationId)
                        ->where('role', 'owner')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
                    if ($userIds === [] && $nation?->state === 'abandoned') {
                        $requests = DB::table('nation_creation_requests')->where('world_id', $world->id)
                            ->where('nation_id', $nationId)->where('status', 'completed')->pluck('user_id')->all();
                        $actors = DB::table('audit_events')->where('world_id', $world->id)->where('nation_id', $nationId)
                            ->where('event_type', 'nation.created')->whereNotNull('actor_user_id')->pluck('actor_user_id')->all();
                        $userIds = array_values(array_unique(array_map('intval', [...$requests, ...$actors])));
                    }
                    if (count($userIds) !== 1) {
                        $reason = 'unknown_or_ambiguous_owner';
                    } else {
                        $secretaryId = Secretary::query()->where('user_id', $userIds[0])->value('id');
                        if ($secretaryId === null || ! SecretarySkill::query()->where('secretary_id', $secretaryId)
                            ->where('skill_key', SecretarySkillCatalog::OIL_DEVELOPMENT)->exists()) {
                            $reason = 'missing_secretary_or_skill';
                        }
                    }
                }
            }
            $identity = [
                'audit_id' => (int) $event->id, 'world_id' => (int) $world->id,
                'nation_id' => $nationId, 'turn' => $event->turn === null ? null : (int) $event->turn,
                'turn_run_id' => $runId, 'queue_item_id' => $queueId,
            ];
            $key = implode(':', [$nationId, $event->turn, $runId, $queueId]);
            if ($reason === null && isset($seen[$key])) {
                $reason = 'duplicate_discovery_event';
            }
            if ($reason !== null) {
                if (in_array($reason, ['not_a_completed_production_turn', 'online_xp_ruleset', 'duplicate_discovery_event'], true)) {
                    $excluded[] = [...$identity, 'reason' => $reason];
                } else {
                    $skipped[] = [...$identity, 'reason' => $reason];
                }

                continue;
            }
            $seen[$key] = true;
            $secretaryId = (int) $secretaryId;
            $candidates[] = [...$identity, 'secretary_id' => $secretaryId, 'experience' => 1];
            $awards[$secretaryId] = ($awards[$secretaryId] ?? 0) + 1;
        }
        ksort($awards);

        return [
            'world_id' => (int) $world->id, 'cutoff_audit_id' => (int) $boundary->cutoff_audit_id,
            'cutoff_turn' => (int) $boundary->cutoff_turn, 'applied_at' => $boundary->applied_at,
            'candidates' => $candidates, 'skipped' => $skipped, 'excluded' => $excluded, 'awards' => $awards,
        ];
    }

    /** @return array<string, mixed> */
    public function apply(World $world): array
    {
        $this->lock->acquire($world);
        try {
            return DB::transaction(function () use ($world): array {
                $this->lock->assertHeld($world);
                $locked = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $boundary = DB::table('oil_discovery_backfills')->where('world_id', $world->id)->lockForUpdate()->first();
                if ($boundary === null || $locked->rulesetVersion->key !== config('hakoniwa.ruleset.key')
                    || TurnRun::query()->where('world_id', $world->id)->unresolvedProduction()->exists()) {
                    throw new RuntimeException('Apply requires the upgraded World, a saved boundary and no unresolved production Turn.');
                }
                $report = $this->preview($locked);
                if ($boundary->applied_at !== null) {
                    return [...$report, 'applied' => false];
                }
                // Missing evidence is not replaced by guessed events or ownership.
                // Leave the whole batch unapplied so its skipped rows can be reviewed.
                if ($report['skipped'] !== []) {
                    throw new RuntimeException('Review skipped oil discovery evidence before applying this batch.');
                }
                $definition = $this->catalog->definition(config('hakoniwa.ruleset'), SecretarySkillCatalog::OIL_DEVELOPMENT);
                SecretarySurfaceState::query()->whereIn('secretary_id', array_keys($report['awards']))
                    ->orderBy('secretary_id')->lockForUpdate()->get();
                foreach ($report['awards'] as $secretaryId => $experience) {
                    $row = SecretarySkill::query()->where('secretary_id', $secretaryId)
                        ->where('skill_key', SecretarySkillCatalog::OIL_DEVELOPMENT)->lockForUpdate()->sole();
                    $next = $this->progression->advance($definition, $row->level, $row->experience, $experience);
                    $row->update(['level' => $next['level'], 'experience' => $next['experience']]);
                }
                DB::table('oil_discovery_backfills')->where('world_id', $world->id)->update(['applied_at' => now()]);

                return [...$report, 'applied' => true];
            }, 1);
        } finally {
            $this->lock->release($world);
        }
    }
}
