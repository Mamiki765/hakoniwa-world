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

/** Aggregates lifetime facts. No receipt deletion or reward settlement. */
final class UndergroundReceiptRollupService
{
    public const VERSION = 1;

    public const BATTLE_VERSION = 2;

    public const STREAMS = ['battle', 'skip', 'bulk_skip', 'intro_request'];

    private const TABLE = 'underground_receipt_rollups';

    private const METRICS = [
        'receipt_count', 'battle_count', 'victory_count',
        'damage_dealt_sum', 'damage_dealt_known_count',
        'damage_received_sum', 'damage_received_known_count', 'skip_tickets_used',
    ];

    public function __construct(private UndergroundLifetimeStatistics $statistics) {}

    /** @return array<string, int> */
    public function totals(int $profileId): array
    {
        $pieces = DB::table(self::TABLE)->where('underground_profile_id', $profileId)->select(self::METRICS)
            ->selectRaw("CASE WHEN stream = 'battle' AND aggregation_version = 1 THEN 1 ELSE 0 END AS legacy_battle_prefix,
                CASE WHEN aggregation_version <> CASE WHEN stream = 'battle' THEN ".self::BATTLE_VERSION.' ELSE '.self::VERSION.' END THEN 1 ELSE 0 END AS unsupported_prefix');
        foreach (self::STREAMS as $stream) {
            $pieces->unionAll($this->sourceTotals($profileId, $stream)->whereRaw(
                'id > COALESCE((SELECT verified_through_id FROM '.self::TABLE.' WHERE underground_profile_id = ? AND stream = ?), 0)',
                [$profileId, $stream],
            ));
        }

        // One SQL statement: saved totals, every watermark and the remaining
        // receipts must share the same snapshot while a collector commits.
        $row = DB::query()->fromSub($pieces, 'journal_parts')
            ->selectRaw($this->sumColumns().', COALESCE(SUM(legacy_battle_prefix), 0) AS legacy_battle_prefix, COALESCE(SUM(unsupported_prefix), 0) AS unsupported_prefix')->first();
        if ((int) $row->legacy_battle_prefix > 0) {
            self::assertCheckpointVersion('battle', (object) ['aggregation_version' => 1]);
        }
        if ((int) $row->unsupported_prefix > 0) {
            throw new RuntimeException('Unsupported receipt aggregation version.');
        }

        return $this->metrics($row);
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
            self::assertCheckpointVersion($stream, $checkpoint);
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
                $reason = $this->preparationBlocker($stream, $candidate, $cutoff);
                if ($reason !== null) {
                    $stopReason = $reason;
                    break;
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
                $statisticsBefore = json_decode($checkpoint->lifetime_statistics ?? '[]', true, 512, JSON_THROW_ON_ERROR);
                $statisticsDelta = $stream === 'battle' ? $this->statistics->range($profileId, $from, $to) : [];
                $statisticsAfter = $this->statistics->merge($statisticsBefore, $statisticsDelta);
                $before = $this->metrics($checkpoint);
                $after = [];
                foreach (self::METRICS as $metric) {
                    $after[$metric] = $before[$metric] + $delta[$metric];
                }
                $now = now();
                $values = [
                    ...$after,
                    'verified_through_id' => $to,
                    'aggregation_version' => self::versionForStream($stream),
                    'lifetime_statistics' => json_encode($statisticsAfter, JSON_THROW_ON_ERROR),
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
                    || $this->metrics($saved) !== $after || $sourceAgain !== $delta
                    || json_decode($saved->lifetime_statistics, true, 512, JSON_THROW_ON_ERROR) != $statisticsAfter
                    || ($stream === 'battle' && $this->statistics->range($profileId, $from, $to) != $statisticsDelta)) {
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

    public static function versionForStream(string $stream): int
    {
        return match ($stream) {
            'battle' => self::BATTLE_VERSION,
            'skip', 'bulk_skip', 'intro_request' => self::VERSION,
            default => throw new InvalidArgumentException('Unknown receipt stream.'),
        };
    }

    public static function assertCheckpointVersion(string $stream, ?stdClass $checkpoint): void
    {
        if ($checkpoint === null || (int) $checkpoint->aggregation_version === self::versionForStream($stream)) {
            return;
        }
        if ($stream === 'battle' && (int) $checkpoint->aggregation_version === 1) {
            throw new UndergroundRuntimeException('underground_statistics_upgrade_required', '戦闘統計の更新を確認中です。冒険日誌は更新完了後に利用できます。');
        }

        throw new RuntimeException('Unsupported receipt aggregation version.');
    }

    /**
     * Explicit, bounded repair of a fully retained v1 prefix. Never settles a
     * battle, advances a boundary, deletes receipts or runs automatically.
     *
     * @return array<string, mixed>
     */
    public function rebuildBattlePrefix(int $profileId, int $maxReceipts = 1_000, bool $apply = false): array
    {
        if ($profileId < 1 || $maxReceipts < 1 || $maxReceipts > 1_000) {
            throw new InvalidArgumentException('Specify an existing profile and a rebuild limit between 1 and 1000.');
        }
        $this->assertSourceSequence('underground_battles');

        return DB::transaction(function () use ($profileId, $maxReceipts, $apply): array {
            UndergroundProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail(['id']);
            $identity = ['underground_profile_id' => $profileId, 'stream' => 'battle'];
            $checkpoint = DB::table(self::TABLE)->where($identity)->first();
            $result = ['profile_id' => $profileId, 'stream' => 'battle', 'applied' => false,
                'verified_through_id' => (int) ($checkpoint->verified_through_id ?? 0)];
            if ($checkpoint === null || (int) $checkpoint->aggregation_version === self::BATTLE_VERSION) {
                return [...$result, 'stop_reason' => $checkpoint === null ? 'no_checkpoint' : 'already_current'];
            }
            if ((int) $checkpoint->aggregation_version !== self::VERSION) {
                throw new RuntimeException('Unsupported receipt aggregation version.');
            }
            if ((int) $checkpoint->deleted_through_id !== 0 || (int) $checkpoint->deleted_receipt_count !== 0) {
                throw new RuntimeException('Cannot rebuild a battle prefix after receipt deletion.');
            }
            $through = (int) $checkpoint->verified_through_id;
            $expectedCount = (int) $checkpoint->receipt_count;
            if ($expectedCount > $maxReceipts) {
                throw new RuntimeException('Battle prefix exceeds the rebuild limit.');
            }
            $rows = DB::table('underground_battles')->where('underground_profile_id', $profileId)
                ->where('id', '<=', $through)->orderBy('id')->limit($maxReceipts + 1)
                ->get(['id', 'finished_at', 'activity_type', 'compaction_version', 'statistics_version', 'statistics']);
            if ($rows->count() !== $expectedCount || (int) $rows->last()?->id !== $through) {
                throw new RuntimeException('Battle prefix source receipt count does not match its checkpoint.');
            }
            foreach ($rows as $row) {
                if ($this->preparationBlocker('battle', $row, CarbonImmutable::now()->addSecond()) !== null
                    || ($row->statistics !== null && (int) $row->statistics_version !== UndergroundBattleStatisticsProjector::VERSION)) {
                    throw new RuntimeException('Battle prefix contains an unprepared receipt.');
                }
            }
            $legacy = $this->metrics($this->sourceTotals($profileId, 'battle', true)->where('id', '<=', $through)->first());
            $legacyStatistics = $this->statistics->range($profileId, 0, $through, true);
            if ($this->metrics($checkpoint) !== $legacy || $checkpoint->lifetime_statistics === null
                || json_decode($checkpoint->lifetime_statistics, true, 512, JSON_THROW_ON_ERROR) != $legacyStatistics) {
                throw new RuntimeException('Legacy battle rollup does not match the retained source prefix.');
            }
            $totals = $this->metrics($this->sourceTotals($profileId, 'battle')->where('id', '<=', $through)->first());
            $statistics = $this->statistics->range($profileId, 0, $through);
            if ($apply) {
                $now = now();
                DB::table(self::TABLE)->where($identity)->update([
                    ...$totals, 'aggregation_version' => self::BATTLE_VERSION,
                    'lifetime_statistics' => json_encode($statistics, JSON_THROW_ON_ERROR),
                    'verified_at' => $now, 'updated_at' => $now,
                    'last_batch' => json_encode(['operation' => 'rebuild_battle_prefix', 'previous_version' => self::VERSION,
                        'from_exclusive' => 0, 'through_inclusive' => $through, 'totals' => $totals], JSON_THROW_ON_ERROR),
                ]);
                $saved = DB::table(self::TABLE)->where($identity)->first();
                if ($saved === null || (int) $saved->aggregation_version !== self::BATTLE_VERSION
                    || (int) $saved->verified_through_id !== $through || (int) $saved->deleted_through_id !== 0
                    || (int) $saved->deleted_receipt_count !== 0 || $this->metrics($saved) !== $totals
                    || json_decode($saved->lifetime_statistics, true, 512, JSON_THROW_ON_ERROR) != $statistics
                    || $this->metrics($this->sourceTotals($profileId, 'battle')->where('id', '<=', $through)->first()) !== $totals
                    || $this->statistics->range($profileId, 0, $through) != $statistics) {
                    throw new RuntimeException('Battle prefix rebuild verification failed.');
                }
            }

            return [...$result, 'applied' => $apply, 'stop_reason' => $apply ? 'rebuilt' : 'ready',
                'totals' => $totals, 'lifetime_statistics' => $statistics];
        }, 1);
    }

    /** @return array{string, string} */
    public function source(string $stream): array
    {
        return match ($stream) {
            'battle' => ['underground_battles', 'finished_at'],
            'skip' => ['underground_skip_settlements', 'settled_at'],
            'bulk_skip' => ['underground_skip_batches', 'settled_at'],
            'intro_request' => ['underground_intro_requests', 'created_at'],
            default => throw new InvalidArgumentException('Unknown receipt stream.'),
        };
    }

    public function preparationBlocker(string $stream, stdClass $receipt, CarbonInterface $cutoff): ?string
    {
        [, $finishedColumn] = $this->source($stream);
        if ($receipt->{$finishedColumn} === null) {
            return 'unfinished_receipt';
        }
        if (CarbonImmutable::parse($receipt->{$finishedColumn})->greaterThanOrEqualTo($cutoff)) {
            return 'retention_window';
        }
        if ($stream === 'battle') {
            if (! in_array($receipt->activity_type, [
                UndergroundBattle::ACTIVITY_EXPLORATION, UndergroundBattle::ACTIVITY_TRIAL,
                UndergroundBattle::ACTIVITY_TUTORIAL, UndergroundBattle::ACTIVITY_STORY,
                UndergroundBattle::ACTIVITY_PLAYTEST, UndergroundBattle::ACTIVITY_GUIDE_DUEL,
                UndergroundBattle::ACTIVITY_EVENT,
            ], true)) {
                return 'unclassified_activity';
            }
            if ((int) $receipt->compaction_version !== UndergroundBattleStorage::COMPACTION_VERSION) {
                return 'legacy_statistics_pending';
            }
        }

        return null;
    }

    private function sourceTotals(int $profileId, string $stream, bool $legacyScope = false): Builder
    {
        [$table, $finishedColumn] = $this->source($stream);
        $query = DB::table($table)->where('underground_profile_id', $profileId)->whereNotNull($finishedColumn);
        if ($stream !== 'battle') {
            $tickets = $stream === 'intro_request' ? '0::bigint' : 'COALESCE(SUM(ticket_cost), 0)';

            return $query->selectRaw('COUNT(*) AS receipt_count, 0::bigint AS battle_count, 0::bigint AS victory_count,
                0::bigint AS damage_dealt_sum, 0::bigint AS damage_dealt_known_count,
                0::bigint AS damage_received_sum, 0::bigint AS damage_received_known_count,
                '.$tickets.' AS skip_tickets_used, 0 AS legacy_battle_prefix, 0 AS unsupported_prefix');
        }
        // Share the lifetime scope while preserving legacy self/party semantics.
        // Story/duel receipts advance the boundary but contribute no journal battle.
        $included = $legacyScope ? UndergroundLifetimeStatistics::LEGACY_JOURNAL_SCOPE : UndergroundLifetimeStatistics::JOURNAL_SCOPE;
        $dealt = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_dealt ELSE (statistics->'self'->>'damage_dealt')::bigint END";
        $received = "CASE WHEN statistics IS NULL AND underground_party_id IS NULL THEN damage_received ELSE (statistics->'self'->>'damage_received')::bigint END";

        return $query->selectRaw("COUNT(*) AS receipt_count,
            COUNT(*) FILTER (WHERE {$included}) AS battle_count,
            COUNT(*) FILTER (WHERE {$included} AND result = 'victory') AS victory_count,
            COALESCE(SUM(CASE WHEN {$included} THEN {$dealt} END), 0) AS damage_dealt_sum,
            COUNT(CASE WHEN {$included} THEN {$dealt} END) AS damage_dealt_known_count,
            COALESCE(SUM(CASE WHEN {$included} THEN {$received} END), 0) AS damage_received_sum,
            COUNT(CASE WHEN {$included} THEN {$received} END) AS damage_received_known_count,
            0::bigint AS skip_tickets_used, 0 AS legacy_battle_prefix, 0 AS unsupported_prefix");
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

    public function assertSourceSequence(string $table): void
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
