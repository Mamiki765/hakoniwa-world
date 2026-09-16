<?php

namespace App\Application;

use App\Models\TurnRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RoutineAuditCompactor
{
    public const VERSION = 2;

    public const UNATTRIBUTED_EVENT_TYPE = 'turn.routine_legacy_unattributed';

    /** @return array{summary_groups:int,unattributed_groups:int,event_rows:int,event_json_bytes:int,by_type:array<string,int>,unattributed_forest_rows:int,unattributed_forest_quantity:int,unattributed_by_reason:array<string,int>} */
    public function preview(Carbon $cutoff, int $limit): array
    {
        $result = [
            'summary_groups' => 0,
            'unattributed_groups' => 0,
            'event_rows' => 0,
            'event_json_bytes' => 0,
            'by_type' => [],
            'unattributed_forest_rows' => 0,
            'unattributed_forest_quantity' => 0,
            'unattributed_by_reason' => [],
        ];
        $remaining = $limit;
        foreach ($this->candidateSummaries($cutoff, $remaining) as $summary) {
            $aggregate = $this->eligibleEventAggregate($summary);
            if ($aggregate['rows'] < 1) {
                continue;
            }
            $result['summary_groups']++;
            $remaining--;
            $result['event_rows'] += $aggregate['rows'];
            $result['event_json_bytes'] += $aggregate['bytes'];
            $this->mergeCounts($result['by_type'], $aggregate['by_type']);
            if ($remaining < 1) {
                break;
            }
        }
        if ($remaining > 0) {
            foreach ($this->candidateUnattributedGroups($cutoff, $remaining) as $group) {
                $aggregate = $this->unattributedForestAggregate(
                    (int) $group->turn_run_id,
                    (int) $group->world_id,
                    (int) $group->turn,
                );
                if ($aggregate['rows'] < 1) {
                    continue;
                }
                $result['unattributed_groups']++;
                $result['event_rows'] += $aggregate['rows'];
                $result['event_json_bytes'] += $aggregate['bytes'];
                $result['by_type']['forest.grown']
                    = ($result['by_type']['forest.grown'] ?? 0) + $aggregate['rows'];
                $result['unattributed_forest_rows'] += $aggregate['rows'];
                $result['unattributed_forest_quantity'] += $aggregate['quantity'];
                $reason = $this->unattributedAggregateEvent(
                    (int) $group->turn_run_id,
                    (int) $group->world_id,
                    (int) $group->turn,
                ) === null
                    ? 'legacy_forest_missing_nation_id'
                    : 'legacy_forest_existing_aggregate_with_raw_rows';
                $result['unattributed_by_reason'][$reason]
                    = ($result['unattributed_by_reason'][$reason] ?? 0) + $aggregate['rows'];
            }
        }
        ksort($result['by_type']);
        ksort($result['unattributed_by_reason']);

        return $result;
    }

    /** @return array{summary_groups:int,unattributed_groups:int,event_rows_deleted:int,stopped_by:string,by_type:array<string,int>,unattributed_forest_rows:int,unattributed_forest_quantity:int} */
    public function compact(Carbon $cutoff, int $limit, int $maxSeconds): array
    {
        $started = microtime(true);
        $result = [
            'summary_groups' => 0,
            'unattributed_groups' => 0,
            'event_rows_deleted' => 0,
            'stopped_by' => 'complete',
            'by_type' => [],
            'unattributed_forest_rows' => 0,
            'unattributed_forest_quantity' => 0,
        ];
        $processedGroups = 0;
        foreach ($this->candidateSummaries($cutoff, $limit) as $summary) {
            if (microtime(true) - $started >= $maxSeconds) {
                $result['stopped_by'] = 'soft_time_budget';
                break;
            }
            $row = $this->compactSummary((int) $summary->id, $cutoff);
            if ($row === null) {
                continue;
            }
            $processedGroups++;
            $result['summary_groups']++;
            $result['event_rows_deleted'] += $row['deleted'];
            $this->mergeCounts($result['by_type'], $row['by_type']);
            if ($processedGroups >= $limit) {
                $result['stopped_by'] = 'group_limit';
                break;
            }
        }
        if ($result['stopped_by'] === 'complete' && $processedGroups < $limit) {
            foreach ($this->candidateUnattributedGroups($cutoff, $limit - $processedGroups) as $group) {
                if (microtime(true) - $started >= $maxSeconds) {
                    $result['stopped_by'] = 'soft_time_budget';
                    break;
                }
                $row = $this->compactUnattributedGroup(
                    (int) $group->turn_run_id,
                    (int) $group->world_id,
                    (int) $group->turn,
                    $cutoff,
                );
                if ($row === null) {
                    continue;
                }
                $processedGroups++;
                $result['unattributed_groups']++;
                $result['event_rows_deleted'] += $row['deleted'];
                $result['unattributed_forest_rows'] += $row['deleted'];
                $result['unattributed_forest_quantity'] += $row['quantity'];
                $result['by_type']['forest.grown']
                    = ($result['by_type']['forest.grown'] ?? 0) + $row['deleted'];
                if ($processedGroups >= $limit) {
                    $result['stopped_by'] = 'group_limit';
                    break;
                }
            }
        }
        ksort($result['by_type']);

        return $result;
    }

    /** @return iterable<int, object> */
    private function candidateSummaries(Carbon $cutoff, int $limit): iterable
    {
        return DB::table('audit_events as summaries')
            ->where('summaries.event_type', 'turn.summary')
            ->where('summaries.occurred_at', '<=', $cutoff)
            ->whereRaw("NOT jsonb_exists(summaries.metadata, 'routine_compaction')")
            ->whereRaw("NOT jsonb_exists(summaries.metadata, 'routine')")
            ->whereExists(static function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('turn_runs as completed_runs')
                    ->where('completed_runs.status', TurnRun::STATUS_COMPLETED)
                    ->where('completed_runs.is_dry_run', false)
                    ->whereNotNull('completed_runs.completed_at')
                    ->where('completed_runs.completed_at', '<=', $cutoff)
                    ->whereColumn('completed_runs.world_id', 'summaries.world_id')
                    ->whereColumn('completed_runs.target_turn', 'summaries.turn')
                    ->whereRaw("completed_runs.id::text = summaries.metadata->>'turn_run_id'");
            })
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_events as routine_events')
                    ->whereColumn('routine_events.world_id', 'summaries.world_id')
                    ->whereColumn('routine_events.nation_id', 'summaries.nation_id')
                    ->whereColumn('routine_events.turn', 'summaries.turn')
                    ->whereRaw("routine_events.metadata->>'turn_run_id' = summaries.metadata->>'turn_run_id'")
                    ->where(static fn ($events) => self::whereEligibleEvent($events, 'routine_events'));
            })
            ->orderBy('summaries.id')
            ->limit($limit)
            ->select(['summaries.id', 'summaries.world_id', 'summaries.nation_id', 'summaries.turn', 'summaries.metadata'])
            ->cursor();
    }

    /** @return iterable<int, object> */
    private function candidateUnattributedGroups(Carbon $cutoff, int $limit): iterable
    {
        $turnRunExpression = "(legacy.metadata->>'turn_run_id')::bigint";

        return DB::table('audit_events as legacy')
            ->where('legacy.event_type', 'forest.grown')
            ->whereNull('legacy.nation_id')
            ->whereRaw("jsonb_typeof(legacy.metadata->'increment') = 'number'")
            ->whereRaw("(legacy.metadata->>'increment')::numeric >= 0")
            ->whereRaw("legacy.metadata->>'turn_run_id' ~ '^[0-9]+$'")
            ->whereExists(static function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('turn_runs as completed_runs')
                    ->where('completed_runs.status', TurnRun::STATUS_COMPLETED)
                    ->where('completed_runs.is_dry_run', false)
                    ->whereNotNull('completed_runs.completed_at')
                    ->where('completed_runs.completed_at', '<=', $cutoff)
                    ->whereColumn('completed_runs.world_id', 'legacy.world_id')
                    ->whereColumn('completed_runs.target_turn', 'legacy.turn')
                    ->whereRaw("completed_runs.id::text = legacy.metadata->>'turn_run_id'");
            })
            ->selectRaw("legacy.world_id, legacy.turn, {$turnRunExpression} as turn_run_id, MIN(legacy.id) as first_event_id")
            ->groupBy('legacy.world_id', 'legacy.turn', DB::raw($turnRunExpression))
            ->orderByRaw('MIN(legacy.id)')
            ->limit($limit)
            ->cursor();
    }

    /** @return array{rows:int,bytes:int,by_type:array<string,int>,routine:array<string,int>,source_id_min:int,source_id_max:int} */
    private function eligibleEventAggregate(object $summary): array
    {
        $query = $this->eligibleEventsQuery($summary);
        $source = (clone $query)->selectRaw(
            'COUNT(*) as row_count, COALESCE(SUM(octet_length(metadata::text)), 0) as json_bytes, MIN(id) as source_id_min, MAX(id) as source_id_max',
        )->first();
        $byType = [];
        $routine = [
            'fire_protection_checks' => 0,
            'forest_growth_cells' => 0,
            'forest_growth_quantity' => 0,
            'population_growth_cells' => 0,
            'population_growth' => 0,
        ];
        foreach ((clone $query)
            ->selectRaw("event_type, COUNT(*) as row_count,
                COALESCE(SUM(CASE WHEN event_type = 'forest.grown' THEN ((metadata->>'increment')::numeric)::bigint ELSE 0 END), 0) as forest_quantity,
                COALESCE(SUM(CASE WHEN event_type = 'population.increased' THEN ((metadata->>'increase')::numeric)::bigint ELSE 0 END), 0) as population_quantity")
            ->groupBy('event_type')->cursor() as $row) {
            $type = (string) $row->event_type;
            $count = (int) $row->row_count;
            $byType[$type] = $count;
            if ($type === 'fire.prevented') {
                $routine['fire_protection_checks'] = $count;
            } elseif ($type === 'forest.grown') {
                $routine['forest_growth_cells'] = $count;
                $routine['forest_growth_quantity'] = max(0, (int) $row->forest_quantity);
            } elseif ($type === 'population.increased') {
                $routine['population_growth_cells'] = $count;
                $routine['population_growth'] = max(0, (int) $row->population_quantity);
            }
        }
        ksort($byType);

        return [
            'rows' => (int) ($source->row_count ?? 0),
            'bytes' => (int) ($source->json_bytes ?? 0),
            'by_type' => $byType,
            'routine' => $routine,
            'source_id_min' => (int) ($source->source_id_min ?? 0),
            'source_id_max' => (int) ($source->source_id_max ?? 0),
        ];
    }

    private function eligibleEventsQuery(object $summary): Builder
    {
        $metadata = $this->metadata($summary->metadata);
        $turnRunId = $metadata['turn_run_id'] ?? null;
        if (! is_numeric($turnRunId)) {
            return DB::table('audit_events')->whereRaw('1 = 0');
        }

        return DB::table('audit_events')
            ->where('world_id', $summary->world_id)
            ->where('nation_id', $summary->nation_id)
            ->where('turn', $summary->turn)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) (int) $turnRunId])
            ->where(static fn ($events) => self::whereEligibleEvent($events, 'audit_events'));
    }

    private static function whereEligibleEvent(Builder $query, string $alias): Builder
    {
        return $query->where("{$alias}.event_type", 'fire.prevented')
            ->orWhere(static function ($forest) use ($alias): void {
                $forest->where("{$alias}.event_type", 'forest.grown')
                    ->whereRaw("jsonb_typeof({$alias}.metadata->'increment') = 'number'")
                    ->whereRaw("({$alias}.metadata->>'increment')::numeric >= 0");
            })
            ->orWhere(static function ($population) use ($alias): void {
                $population->where("{$alias}.event_type", 'population.increased')
                    ->whereRaw("jsonb_typeof({$alias}.metadata->'increase') = 'number'")
                    ->whereRaw("({$alias}.metadata->>'increase')::numeric >= 0");
            })
            ->orWhere(static function ($sale) use ($alias): void {
                $sale->where("{$alias}.event_type", 'resource.automatic_sale')
                    ->whereRaw("jsonb_typeof({$alias}.metadata->'requested') = 'number'")
                    ->whereRaw("jsonb_typeof({$alias}.metadata->'sold') = 'number'")
                    ->whereRaw("jsonb_typeof({$alias}.metadata->'revenue') = 'number'")
                    ->whereRaw("({$alias}.metadata->>'requested')::numeric = 0")
                    ->whereRaw("({$alias}.metadata->>'sold')::numeric = 0")
                    ->whereRaw("({$alias}.metadata->>'revenue')::numeric = 0");
            });
    }

    /** @return array{deleted:int,by_type:array<string,int>}|null */
    private function compactSummary(int $summaryId, Carbon $cutoff): ?array
    {
        return DB::transaction(function () use ($summaryId, $cutoff): ?array {
            $summary = DB::table('audit_events')->where('id', $summaryId)->lockForUpdate()->first();
            if ($summary === null || $summary->event_type !== 'turn.summary'
                || Carbon::parse((string) $summary->occurred_at)->isAfter($cutoff)) {
                return null;
            }
            $metadata = $this->metadata($summary->metadata);
            if (is_array($metadata['routine_compaction'] ?? null)
                || is_array($metadata['routine'] ?? null)) {
                return null;
            }
            $turnRunId = $metadata['turn_run_id'] ?? null;
            if (! is_numeric($turnRunId)
                || ! DB::table('turn_runs')->where('id', (int) $turnRunId)
                    ->where('world_id', (int) $summary->world_id)
                    ->where('target_turn', (int) $summary->turn)
                    ->where('status', TurnRun::STATUS_COMPLETED)->where('is_dry_run', false)
                    ->whereNotNull('completed_at')->where('completed_at', '<=', $cutoff)->exists()) {
                return null;
            }
            $aggregate = $this->eligibleEventAggregate($summary);
            if ($aggregate['rows'] < 1) {
                return null;
            }
            $unattributed = $this->unattributedForestEvidence(
                (int) $turnRunId,
                (int) $summary->world_id,
                (int) $summary->turn,
            );
            $routine = $aggregate['routine'];
            $routine['forest_growth_attribution'] = [
                'complete' => $unattributed['rows'] === 0,
                'known_cells' => $routine['forest_growth_cells'],
                'known_quantity' => $routine['forest_growth_quantity'],
                'unattributed_turn_run_cells' => $unattributed['rows'],
                'unattributed_turn_run_quantity' => $unattributed['quantity'],
            ];
            if ($unattributed['rows'] > 0) {
                $routine['forest_growth_cells'] = null;
                $routine['forest_growth_quantity'] = null;
            }
            $metadata['routine'] = $routine;
            $metadata['routine_compaction'] = [
                'version' => self::VERSION,
                'source_event_count' => $aggregate['rows'],
                'source_event_id_min' => $aggregate['source_id_min'],
                'source_event_id_max' => $aggregate['source_id_max'],
                'compacted_at' => Carbon::now()->toAtomString(),
                'forest_attribution_complete' => $unattributed['rows'] === 0,
            ];
            DB::table('audit_events')->where('id', $summaryId)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => Carbon::now(),
            ]);
            $deleted = $this->eligibleEventsQuery($summary)->delete();
            if ($deleted !== $aggregate['rows']) {
                throw new \RuntimeException('Routine audit compaction did not delete its exact source set.');
            }

            return ['deleted' => $deleted, 'by_type' => $aggregate['by_type']];
        }, 3);
    }

    /** @return array{deleted:int,quantity:int}|null */
    private function compactUnattributedGroup(int $turnRunId, int $worldId, int $turn, Carbon $cutoff): ?array
    {
        return DB::transaction(function () use ($turnRunId, $worldId, $turn, $cutoff): ?array {
            $run = DB::table('turn_runs')->where('id', $turnRunId)->lockForUpdate()->first();
            if ($run === null || (int) $run->world_id !== $worldId
                || (int) $run->target_turn !== $turn
                || $run->status !== TurnRun::STATUS_COMPLETED || (bool) $run->is_dry_run
                || $run->completed_at === null
                || Carbon::parse((string) $run->completed_at)->isAfter($cutoff)) {
                return null;
            }
            $existing = $this->unattributedAggregateEvent($turnRunId, $worldId, $turn);
            $aggregate = $this->unattributedForestAggregate($turnRunId, $worldId, $turn);
            if ($existing !== null) {
                if ($aggregate['rows'] > 0) {
                    throw new \RuntimeException('Unattributed legacy forest aggregate coexists with undeleted source rows.');
                }

                return null;
            }
            if ($aggregate['rows'] < 1) {
                return null;
            }
            $now = Carbon::now();
            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'event_type' => self::UNATTRIBUTED_EVENT_TYPE,
                'world_id' => $worldId,
                'turn' => $turn,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'admin',
                'severity' => 'warning',
                'subject_type' => (new TurnRun)->getMorphClass(),
                'subject_id' => $turnRunId,
                'metadata' => json_encode([
                    'turn_run_id' => $turnRunId,
                    'routine' => [
                        'forest_growth_unattributed_cells' => $aggregate['rows'],
                        'forest_growth_unattributed_quantity' => $aggregate['quantity'],
                    ],
                    'routine_compaction' => [
                        'version' => self::VERSION,
                        'provenance' => 'legacy_forest_missing_nation_id',
                        'source_event_count' => $aggregate['rows'],
                        'source_event_id_min' => $aggregate['source_id_min'],
                        'source_event_id_max' => $aggregate['source_id_max'],
                        'compacted_at' => $now->toAtomString(),
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $deleted = $this->unattributedEventsQuery($turnRunId, $worldId, $turn)->delete();
            if ($deleted !== $aggregate['rows']) {
                throw new \RuntimeException('Unattributed legacy forest compaction did not delete its exact source set.');
            }

            return ['deleted' => $deleted, 'quantity' => $aggregate['quantity']];
        }, 3);
    }

    /** @return array{rows:int,quantity:int,bytes:int,source_id_min:int,source_id_max:int} */
    private function unattributedForestAggregate(int $turnRunId, int $worldId, int $turn): array
    {
        $row = $this->unattributedEventsQuery($turnRunId, $worldId, $turn)
            ->selectRaw("COUNT(*) as row_count,
                COALESCE(SUM(((metadata->>'increment')::numeric)::bigint), 0) as quantity,
                COALESCE(SUM(octet_length(metadata::text)), 0) as json_bytes,
                MIN(id) as source_id_min, MAX(id) as source_id_max")
            ->first();

        return [
            'rows' => (int) ($row->row_count ?? 0),
            'quantity' => max(0, (int) ($row->quantity ?? 0)),
            'bytes' => (int) ($row->json_bytes ?? 0),
            'source_id_min' => (int) ($row->source_id_min ?? 0),
            'source_id_max' => (int) ($row->source_id_max ?? 0),
        ];
    }

    private function unattributedEventsQuery(int $turnRunId, int $worldId, int $turn): Builder
    {
        return DB::table('audit_events')
            ->where('event_type', 'forest.grown')
            ->where('world_id', $worldId)
            ->where('turn', $turn)
            ->whereNull('nation_id')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $turnRunId])
            ->whereRaw("jsonb_typeof(metadata->'increment') = 'number'")
            ->whereRaw("(metadata->>'increment')::numeric >= 0");
    }

    /** @return array{rows:int,quantity:int} */
    private function unattributedForestEvidence(int $turnRunId, int $worldId, int $turn): array
    {
        $raw = $this->unattributedForestAggregate($turnRunId, $worldId, $turn);
        if ($raw['rows'] > 0) {
            if ($this->unattributedAggregateEvent($turnRunId, $worldId, $turn) !== null) {
                throw new \RuntimeException('Unattributed legacy forest aggregate coexists with undeleted source rows.');
            }

            return ['rows' => $raw['rows'], 'quantity' => $raw['quantity']];
        }
        $event = $this->unattributedAggregateEvent($turnRunId, $worldId, $turn);
        if ($event === null) {
            return ['rows' => 0, 'quantity' => 0];
        }
        $metadata = $this->metadata($event->metadata);
        $routine = is_array($metadata['routine'] ?? null) ? $metadata['routine'] : [];

        return [
            'rows' => max(0, (int) ($routine['forest_growth_unattributed_cells'] ?? 0)),
            'quantity' => max(0, (int) ($routine['forest_growth_unattributed_quantity'] ?? 0)),
        ];
    }

    private function unattributedAggregateEvent(int $turnRunId, int $worldId, int $turn): ?object
    {
        return DB::table('audit_events')
            ->where('event_type', self::UNATTRIBUTED_EVENT_TYPE)
            ->where('world_id', $worldId)
            ->where('turn', $turn)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $turnRunId])
            ->whereRaw("(metadata->'routine_compaction'->>'version')::integer = ?", [self::VERSION])
            ->first(['id', 'metadata']);
    }

    /** @param array<string, int> $target
     * @param  array<string, int>  $source
     */
    private function mergeCounts(array &$target, array $source): void
    {
        foreach ($source as $type => $count) {
            $target[$type] = ($target[$type] ?? 0) + $count;
        }
    }

    /** @return array<string, mixed> */
    private function metadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
