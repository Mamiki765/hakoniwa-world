<?php

namespace App\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RoutineAuditCompactor
{
    public const VERSION = 1;

    private const EVENT_TYPES = [
        'fire.prevented',
        'forest.grown',
        'population.increased',
        'resource.automatic_sale',
    ];

    /** @return array{summary_groups:int,event_rows:int,event_json_bytes:int,by_type:array<string,int>} */
    public function preview(Carbon $cutoff, int $limit): array
    {
        $groups = 0;
        $rows = 0;
        $bytes = 0;
        $byType = [];
        foreach ($this->candidateSummaries($cutoff, $limit) as $summary) {
            $events = $this->eligibleEvents($summary);
            if ($events === []) {
                continue;
            }
            $groups++;
            foreach ($events as $event) {
                $rows++;
                $type = (string) $event->event_type;
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                $bytes += strlen((string) $event->metadata);
            }
        }
        ksort($byType);

        return [
            'summary_groups' => $groups,
            'event_rows' => $rows,
            'event_json_bytes' => $bytes,
            'by_type' => $byType,
        ];
    }

    /** @return array{summary_groups:int,event_rows_deleted:int,stopped_by:string,by_type:array<string,int>} */
    public function compact(Carbon $cutoff, int $limit, int $maxSeconds): array
    {
        $started = microtime(true);
        $result = ['summary_groups' => 0, 'event_rows_deleted' => 0, 'stopped_by' => 'complete', 'by_type' => []];
        foreach ($this->candidateSummaries($cutoff, $limit) as $summary) {
            if (microtime(true) - $started >= $maxSeconds) {
                $result['stopped_by'] = 'time_limit';
                break;
            }
            $row = $this->compactSummary((int) $summary->id, $cutoff);
            if ($row === null) {
                continue;
            }
            $result['summary_groups']++;
            $result['event_rows_deleted'] += $row['deleted'];
            foreach ($row['by_type'] as $type => $count) {
                $result['by_type'][$type] = ($result['by_type'][$type] ?? 0) + $count;
            }
            if ($result['summary_groups'] >= $limit) {
                $result['stopped_by'] = 'group_limit';
                break;
            }
        }
        ksort($result['by_type']);

        return $result;
    }

    /** @return list<object> */
    private function candidateSummaries(Carbon $cutoff, int $limit): array
    {
        $candidates = [];
        foreach (DB::table('audit_events as summaries')
            ->where('summaries.event_type', 'turn.summary')
            ->where('summaries.occurred_at', '<=', $cutoff)
            ->whereRaw("NOT jsonb_exists(summaries.metadata, 'routine_compaction')")
            ->whereRaw("NOT jsonb_exists(summaries.metadata, 'routine')")
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('turn_runs as completed_runs')
                    ->where('completed_runs.status', 'completed')
                    ->where('completed_runs.is_dry_run', false)
                    ->whereRaw("completed_runs.id::text = summaries.metadata->>'turn_run_id'");
            })
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_events as routine_events')
                    ->whereColumn('routine_events.world_id', 'summaries.world_id')
                    ->whereColumn('routine_events.nation_id', 'summaries.nation_id')
                    ->whereColumn('routine_events.turn', 'summaries.turn')
                    ->whereRaw("routine_events.metadata->>'turn_run_id' = summaries.metadata->>'turn_run_id'")
                    ->where(static function ($events): void {
                        $events->where('routine_events.event_type', 'fire.prevented')
                            ->orWhere(static function ($forest): void {
                                $forest->where('routine_events.event_type', 'forest.grown')
                                    ->whereRaw("jsonb_typeof(routine_events.metadata->'increment') = 'number'");
                            })
                            ->orWhere(static function ($population): void {
                                $population->where('routine_events.event_type', 'population.increased')
                                    ->whereRaw("jsonb_typeof(routine_events.metadata->'increase') = 'number'");
                            })
                            ->orWhere(static function ($sale): void {
                                $sale->where('routine_events.event_type', 'resource.automatic_sale')
                                    ->whereRaw("jsonb_typeof(routine_events.metadata->'requested') = 'number'")
                                    ->whereRaw("jsonb_typeof(routine_events.metadata->'sold') = 'number'")
                                    ->whereRaw("jsonb_typeof(routine_events.metadata->'revenue') = 'number'")
                                    ->whereRaw("(routine_events.metadata->>'requested')::numeric = 0")
                                    ->whereRaw("(routine_events.metadata->>'sold')::numeric = 0")
                                    ->whereRaw("(routine_events.metadata->>'revenue')::numeric = 0");
                            });
                    });
            })
            ->orderBy('summaries.id')
            ->limit($limit)
            ->get(['summaries.id', 'summaries.world_id', 'summaries.nation_id', 'summaries.turn', 'summaries.metadata']) as $summary) {
            if ($this->eligibleEvents($summary) === []) {
                continue;
            }
            $candidates[] = $summary;
        }

        return $candidates;
    }

    /** @return list<object> */
    private function eligibleEvents(object $summary): array
    {
        $summaryMetadata = $this->metadata($summary->metadata);
        $turnRunId = $summaryMetadata['turn_run_id'] ?? null;
        if (! is_numeric($turnRunId)
            || ! DB::table('turn_runs')->where('id', (int) $turnRunId)
                ->where('status', 'completed')->where('is_dry_run', false)->exists()) {
            return [];
        }
        $events = [];
        foreach (DB::table('audit_events')
            ->where('world_id', $summary->world_id)
            ->where('nation_id', $summary->nation_id)
            ->where('turn', $summary->turn)
            ->whereIn('event_type', self::EVENT_TYPES)
            ->orderBy('id')
            ->get(['id', 'event_type', 'metadata']) as $event) {
            $metadata = $this->metadata($event->metadata);
            if ((int) ($metadata['turn_run_id'] ?? 0) !== (int) $turnRunId) {
                continue;
            }
            if ($event->event_type === 'forest.grown' && ! is_numeric($metadata['increment'] ?? null)) {
                continue;
            }
            if ($event->event_type === 'population.increased' && ! is_numeric($metadata['increase'] ?? null)) {
                continue;
            }
            if ($event->event_type === 'resource.automatic_sale'
                && (! is_numeric($metadata['requested'] ?? null)
                    || ! is_numeric($metadata['sold'] ?? null)
                    || ! is_numeric($metadata['revenue'] ?? null)
                    || (int) $metadata['requested'] !== 0
                    || (int) $metadata['sold'] !== 0
                    || (int) $metadata['revenue'] !== 0)) {
                continue;
            }
            $events[] = $event;
        }

        return $events;
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
            $events = $this->eligibleEvents($summary);
            if ($events === []) {
                return null;
            }

            $routine = [
                'fire_protection_checks' => 0,
                'forest_growth_cells' => 0,
                'forest_growth_quantity' => 0,
                'population_growth_cells' => 0,
                'population_growth' => 0,
            ];
            $ids = [];
            $byType = [];
            foreach ($events as $event) {
                $eventMetadata = $this->metadata($event->metadata);
                $ids[] = (int) $event->id;
                $type = (string) $event->event_type;
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                match ($type) {
                    'fire.prevented' => $routine['fire_protection_checks']++,
                    'forest.grown' => $this->addForest($routine, $eventMetadata),
                    'population.increased' => $this->addPopulation($routine, $eventMetadata),
                    default => null,
                };
            }
            $metadata['routine'] = $routine;
            $metadata['routine_compaction'] = [
                'version' => self::VERSION,
                'source_event_count' => count($ids),
                'source_event_id_min' => min($ids),
                'source_event_id_max' => max($ids),
                'compacted_at' => Carbon::now()->toAtomString(),
            ];
            DB::table('audit_events')->where('id', $summaryId)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => Carbon::now(),
            ]);
            $deleted = DB::table('audit_events')->whereIn('id', $ids)->delete();
            if ($deleted !== count($ids)) {
                throw new \RuntimeException('Routine audit compaction did not delete its exact locked source set.');
            }

            return ['deleted' => $deleted, 'by_type' => $byType];
        }, 3);
    }

    /** @param array<string, int> $routine
     * @param  array<string, mixed>  $metadata
     */
    private function addForest(array &$routine, array $metadata): null
    {
        $routine['forest_growth_cells']++;
        $routine['forest_growth_quantity'] += max(0, (int) ($metadata['increment'] ?? 0));

        return null;
    }

    /** @param array<string, int> $routine
     * @param  array<string, mixed>  $metadata
     */
    private function addPopulation(array &$routine, array $metadata): null
    {
        $routine['population_growth_cells']++;
        $routine['population_growth'] += max(0, (int) ($metadata['increase'] ?? 0));

        return null;
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
