<?php

namespace App\Application\Underground;

use App\Models\UndergroundBattle;
use App\Models\UndergroundProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** Aggregates journal facts only. No receipt deletion or reward settlement. */
final class UndergroundReceiptRollupService
{
    public const VERSION = 1;

    public const STREAMS = ['battle', 'skip', 'bulk_skip'];

    private const TABLE = 'underground_receipt_rollups';

    private const METRICS = [
        'receipt_count', 'battle_count', 'victory_count',
        'damage_dealt_sum', 'damage_dealt_known_count',
        'damage_received_sum', 'damage_received_known_count', 'skip_tickets_used',
    ];

    /** @return array<string, int> */
    public function totals(int $profileId): array
    {
        $pieces = DB::table(self::TABLE)->where('underground_profile_id', $profileId)->select(self::METRICS);
        foreach (self::STREAMS as $stream) {
            $pieces->unionAll($this->sourceTotals($profileId, $stream)->whereRaw(
                'id > COALESCE((SELECT verified_through_id FROM '.self::TABLE.' WHERE underground_profile_id = ? AND stream = ?), 0)',
                [$profileId, $stream],
            ));
        }

        // One SQL statement: saved totals, every watermark and the remaining
        // receipts must share the same snapshot while a collector commits.
        return $this->metrics(DB::query()->fromSub($pieces, 'journal_parts')
            ->selectRaw($this->sumColumns())->first());
    }

    /**
     * One bounded, contiguous prefix of one profile/stream. The checkpoint
     * advances only with verified totals in the same transaction.
     *
     * @return array{profile_id:int,stream:string,from_id:int,candidate_through_id:int,verified_through_id:int,receipts:int,applied:bool,stop_reason:string,delta:array<string,int>}
     */
    public function aggregate(
        int $profileId,
        string $stream,
        CarbonInterface $cutoff,
        int $batchSize = 500,
        bool $apply = false,
    ): array {
        [$table, $finishedColumn] = $this->source($stream);
        if ($profileId < 1 || $batchSize < 1 || $batchSize > 1_000) {
            throw new InvalidArgumentException('Specify an existing profile and a batch size between 1 and 1000.');
        }
        $this->assertSourceSequence($table);

        return DB::transaction(function () use ($profileId, $stream, $cutoff, $batchSize, $apply, $table, $finishedColumn): array {
            // All supported receipt writers take this lock before allocating an
            // ID. Never replace this with SKIP LOCKED and jump over a writer.
            UndergroundProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail(['id']);
            $checkpoint = DB::table(self::TABLE)->where('underground_profile_id', $profileId)->where('stream', $stream)->first();
            if ($checkpoint !== null && (int) $checkpoint->aggregation_version !== self::VERSION) {
                throw new RuntimeException('Unsupported receipt aggregation version.');
            }
            $from = (int) ($checkpoint->verified_through_id ?? 0);
            $columns = ['id', $finishedColumn];
            if ($stream === 'battle') {
                $columns = [...$columns, 'activity_type', 'compaction_version'];
            }
            $candidates = DB::table($table)->where('underground_profile_id', $profileId)
                ->where('id', '>', $from)->orderBy('id')->limit($batchSize)->get($columns);
            $to = $from;
            $count = 0;
            $stopReason = $candidates->count() === $batchSize ? 'batch_limit' : 'no_more_receipts';
            foreach ($candidates as $candidate) {
                $finished = $candidate->{$finishedColumn};
                if ($finished === null) {
                    $stopReason = 'unfinished_receipt';
                    break;
                }
                if (CarbonImmutable::parse($finished)->isAfter($cutoff)) {
                    $stopReason = 'retention_window';
                    break;
                }
                if ($stream === 'battle') {
                    if (! in_array($candidate->activity_type, [
                        UndergroundBattle::ACTIVITY_EXPLORATION, UndergroundBattle::ACTIVITY_TRIAL,
                        UndergroundBattle::ACTIVITY_TUTORIAL, UndergroundBattle::ACTIVITY_STORY,
                        UndergroundBattle::ACTIVITY_PLAYTEST, UndergroundBattle::ACTIVITY_GUIDE_DUEL,
                    ], true)) {
                        $stopReason = 'unclassified_activity';
                        break;
                    }
                    // The older compactor can still backfill statistics on a
                    // legacy row. Do not freeze those numbers before it finishes.
                    if ((int) $candidate->compaction_version !== UndergroundBattleStorage::COMPACTION_VERSION) {
                        $stopReason = 'legacy_statistics_pending';
                        break;
                    }
                }
                $to = (int) $candidate->id;
                $count++;
            }
            $delta = $this->metrics($this->sourceTotals($profileId, $stream)
                ->where('id', '>', $from)->where('id', '<=', $to)->first());
            if ($delta['receipt_count'] !== $count) {
                throw new RuntimeException('Receipt prefix changed before aggregation.');
            }

            if ($apply && $count > 0) {
                $before = $this->metrics($checkpoint);
                $after = [];
                foreach (self::METRICS as $metric) {
                    $after[$metric] = $before[$metric] + $delta[$metric];
                }
                $now = now();
                $values = [
                    ...$after,
                    'verified_through_id' => $to,
                    'aggregation_version' => self::VERSION,
                    'verified_at' => $now,
                    'last_batch' => json_encode([
                        'from_exclusive' => $from, 'through_inclusive' => $to,
                        'cutoff' => $cutoff->toIso8601String(), 'delta' => $delta,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];
                $identity = ['underground_profile_id' => $profileId, 'stream' => $stream];
                if ($checkpoint === null) {
                    DB::table(self::TABLE)->insert([...$identity, ...$values, 'created_at' => $now]);
                } else {
                    DB::table(self::TABLE)->where($identity)->update($values);
                }

                $saved = DB::table(self::TABLE)->where($identity)->first();
                $sourceAgain = $this->metrics($this->sourceTotals($profileId, $stream)
                    ->where('id', '>', $from)->where('id', '<=', $to)->first());
                if ($saved === null || (int) $saved->verified_through_id !== $to
                    || $this->metrics($saved) !== $after || $sourceAgain !== $delta) {
                    // Neither the totals nor the boundary may survive a failed
                    // read-back comparison. Raw receipts are still untouched.
                    throw new RuntimeException('Receipt rollup verification failed.');
                }
            }

            return [
                'profile_id' => $profileId, 'stream' => $stream,
                'from_id' => $from, 'candidate_through_id' => $to,
                'verified_through_id' => $apply ? $to : $from,
                'receipts' => $count, 'applied' => $apply && $count > 0,
                'stop_reason' => $stopReason, 'delta' => $delta,
            ];
        }, 1);
    }

    /** @return array{string, string} */
    private function source(string $stream): array
    {
        return match ($stream) {
            'battle' => ['underground_battles', 'finished_at'],
            'skip' => ['underground_skip_settlements', 'settled_at'],
            'bulk_skip' => ['underground_skip_batches', 'settled_at'],
            default => throw new InvalidArgumentException('Unknown receipt stream.'),
        };
    }

    private function sourceTotals(int $profileId, string $stream): Builder
    {
        [$table, $finishedColumn] = $this->source($stream);
        $query = DB::table($table)->where('underground_profile_id', $profileId)->whereNotNull($finishedColumn);
        if ($stream !== 'battle') {
            return $query->selectRaw('COUNT(*) AS receipt_count, 0::bigint AS battle_count, 0::bigint AS victory_count,
                0::bigint AS damage_dealt_sum, 0::bigint AS damage_dealt_known_count,
                0::bigint AS damage_received_sum, 0::bigint AS damage_received_known_count,
                COALESCE(SUM(ticket_cost), 0) AS skip_tickets_used');
        }
        // Keep the existing journal's scope and legacy self/party semantics.
        // Story/duel receipts advance the boundary but contribute no journal battle.
        $included = "(activity_type IN ('exploration', 'trial', 'playtest') OR (activity_type = 'tutorial' AND activity_key = 'first_descent_tutorial'))";
        $dealt = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_dealt ELSE (statistics->'self'->>'damage_dealt')::bigint END";
        $received = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_received ELSE (statistics->'self'->>'damage_received')::bigint END";

        return $query->selectRaw("COUNT(*) AS receipt_count,
            COUNT(*) FILTER (WHERE {$included}) AS battle_count,
            COUNT(*) FILTER (WHERE {$included} AND result = 'victory') AS victory_count,
            COALESCE(SUM(CASE WHEN {$included} THEN {$dealt} END), 0) AS damage_dealt_sum,
            COUNT(CASE WHEN {$included} THEN {$dealt} END) AS damage_dealt_known_count,
            COALESCE(SUM(CASE WHEN {$included} THEN {$received} END), 0) AS damage_received_sum,
            COUNT(CASE WHEN {$included} THEN {$received} END) AS damage_received_known_count,
            0::bigint AS skip_tickets_used");
    }

    private function sumColumns(): string
    {
        return implode(', ', array_map(
            static fn (string $metric): string => "COALESCE(SUM({$metric}), 0) AS {$metric}",
            self::METRICS,
        ));
    }

    /** @return array<string, int> */
    private function metrics(?stdClass $row): array
    {
        $metrics = [];
        foreach (self::METRICS as $metric) {
            $metrics[$metric] = (int) ($row->{$metric} ?? 0);
        }

        return $metrics;
    }

    private function assertSourceSequence(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receipt rollups require PostgreSQL.');
        }
        $sequence = DB::selectOne(
            "SELECT seqcache, seqincrement, seqcycle FROM pg_sequence WHERE seqrelid = pg_get_serial_sequence(?, 'id')::regclass",
            [$table],
        );
        if ($sequence === null || (int) $sequence->seqcache !== 1 || (int) $sequence->seqincrement !== 1
            || ! in_array($sequence->seqcycle, [false, 0, '0', 'f'], true)) {
            throw new RuntimeException("{$table}: ID checkpoints require an ascending, non-cycling CACHE 1 sequence. No source data was changed.");
        }
    }
}
