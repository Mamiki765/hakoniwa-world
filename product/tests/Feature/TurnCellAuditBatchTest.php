<?php

namespace Tests\Feature;

use App\Application\TurnEventRecorder;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\TurnRun;
use App\Models\World;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class TurnCellAuditBatchTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_cell_events_from_separate_recorders_keep_capture_time_values_and_id_order(): void
    {
        $context = $this->context($this->lightweightWorld());
        $first = app(TurnEventRecorder::class);
        $second = app(TurnEventRecorder::class);
        $firstAt = CarbonImmutable::parse('2026-10-31T03:00:00Z');
        $manyAt = $firstAt->addSeconds(5);
        $flushAt = $firstAt->addSeconds(10);
        $this->travelTo($firstAt);
        $first->record($context, 'turn.audit_before', $context->world, ['step' => 0]);
        $this->assertCount(1, $this->rows($context));

        $metrics = $first->duringCellProcessing($context, function () use ($context, $first, $second, $manyAt, $flushAt): array {
            $metadata = ['step' => 1, 'transition' => ['from' => 'village', 'to' => 'town']];
            $first->record($context, 'settlement.stage_transitioned', $context->world, $metadata);
            $metadata['transition']['to'] = 'city';
            $this->travelTo($manyAt);
            $second->recordMany($context, [
                [
                    'event_type' => 'fire.damaged', 'subject' => $context->world,
                    'metadata' => ['step' => 2, 'later_transition' => $metadata['transition']],
                    'visibility' => null, 'severity' => null, 'message' => null,
                ],
                [
                    'event_type' => 'facility.riot', 'subject' => $context->world,
                    'metadata' => ['step' => 3], 'visibility' => 'private',
                    'severity' => 'critical', 'message' => 'Captured message',
                ],
            ], batchSize: 1);
            $this->assertCount(1, $this->rows($context));
            $this->travelTo($flushAt);

            return ['events' => 3];
        });

        // Other phases retain immediate persistence, including recordMany().
        $second->recordMany($context, [[
            'event_type' => 'turn.audit_after', 'subject' => $context->world,
            'metadata' => ['step' => 4], 'visibility' => null, 'severity' => null, 'message' => null,
        ]]);
        $rows = $this->rows($context);
        $this->assertSame(['events' => 3], $metrics);
        $this->assertSame([
            'turn.audit_before', 'settlement.stage_transitioned', 'fire.damaged', 'facility.riot', 'turn.audit_after',
        ], $rows->pluck('event_type')->all());
        $metadata = $rows->map(fn (object $row): array => $this->metadata($row))->all();
        $this->assertSame(range(0, 4), array_column($metadata, 'step'));
        $this->assertSame(['village', 'town'], [$metadata[1]['transition']['from'], $metadata[1]['transition']['to']]);
        $this->assertSame(['village', 'city'], [$metadata[2]['later_transition']['from'], $metadata[2]['later_transition']['to']]);
        $this->assertSame(['public', 'public', 'public', 'private', 'public'], $rows->pluck('visibility')->all());
        $this->assertSame(['info', 'info', 'info', 'critical', 'info'], $rows->pluck('severity')->all());
        $this->assertSame([null, null, null, 'Captured message', null], $rows->pluck('message')->all());
        foreach ($rows as $index => $row) {
            $at = [$firstAt, $firstAt, $manyAt, $manyAt, $flushAt][$index];
            $this->assertSame($at->toIso8601String(), CarbonImmutable::parse($row->occurred_at)->toIso8601String());
            $this->assertSame($at->format('Y-m-d H:i:s'), $row->created_at);
            $this->assertSame($row->created_at, $row->updated_at);
            $this->assertSame($context->world->getMorphClass(), $row->subject_type);
            $this->assertSame($context->world->id, (int) $row->subject_id);
            $this->assertSame($context->run->id, $metadata[$index]['turn_run_id']);
        }
    }

    public function test_one_thousand_cell_events_flush_before_the_tail_without_reordering_record_many(): void
    {
        $context = $this->context($this->lightweightWorld());
        $recorder = app(TurnEventRecorder::class);
        $auditInserts = 0;
        DB::listen(static function (QueryExecuted $query) use (&$auditInserts): void {
            if (str_starts_with($query->sql, 'insert into "audit_events" ')) {
                $auditInserts++;
            }
        });

        $recorder->duringCellProcessing($context, function () use ($context, $recorder, &$auditInserts): array {
            // A bounded write set must flush at 1,000, including a recordMany()
            // that crosses the boundary; its caller batch size must not reorder rows.
            for ($ordinal = 0; $ordinal < 999; $ordinal++) {
                $recorder->record($context, 'turn.audit_capacity', $context->world, ['ordinal' => $ordinal]);
            }
            $this->assertCount(0, $this->rows($context));
            $this->assertSame(0, $auditInserts);
            app(TurnEventRecorder::class)->recordMany($context, array_map(fn (int $ordinal): array => [
                'event_type' => 'turn.audit_capacity', 'subject' => $context->world,
                'metadata' => ['ordinal' => $ordinal], 'visibility' => null, 'severity' => null, 'message' => null,
            ], [999, 1_000]), batchSize: 1);
            $this->assertCount(1_000, $this->rows($context));
            $this->assertSame(1, $auditInserts);

            return [];
        });

        $this->assertSame(2, $auditInserts);
        $this->assertSame(range(0, 1_000), $this->rows($context)
            ->map(fn (object $row): int => $this->metadata($row)['ordinal'])->all());
    }

    public function test_failed_cell_batch_rolls_back_flushed_rows_and_drops_its_tail_before_replay(): void
    {
        $context = $this->context($this->lightweightWorld());
        $recorder = app(TurnEventRecorder::class);
        $originalName = $context->world->name;
        $this->travelTo(CarbonImmutable::parse('2026-10-31T03:00:00Z'));

        try {
            DB::transaction(function () use ($context, $recorder): void {
                $recorder->duringCellProcessing($context, function () use ($context, $recorder): array {
                    $context->world->update(['name' => 'Cell audit rollback probe']);
                    $recorder->recordMany($context, array_map(fn (int $ordinal): array => [
                        'event_type' => 'turn.audit_failed', 'subject' => $context->world,
                        'metadata' => ['ordinal' => $ordinal], 'visibility' => null, 'severity' => null, 'message' => null,
                    ], range(0, 999)));
                    $this->assertCount(1_000, $this->rows($context));
                    $recorder->record($context, 'turn.audit_failed_tail', $context->world);

                    throw new RuntimeException('Cell audit rollback probe');
                });
            });
            $this->fail('Expected the cell-processing failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cell audit rollback probe', $exception->getMessage());
        }

        $this->assertSame($originalName, $context->world->refresh()->name);
        $this->assertCount(0, $this->rows($context));
        $recorder->record($context, 'turn.audit_after_failure', $context->world);
        $this->assertCount(1, $this->rows($context));

        // The failed scope must leave even the same TurnState reusable. An actual
        // TurnRunner retry uses a new Context/State and the same run and seed.
        foreach ([$context, $this->context($context->world, $context->run)] as $replay) {
            $recorder->duringCellProcessing($replay, function () use ($replay): array {
                app(TurnEventRecorder::class)->record($replay, 'turn.audit_replay', $replay->world, [
                    'draw' => $replay->random->stream('audit-replay-fixture')->integer(1, 1_000_000),
                ]);

                return [];
            });
        }

        $rows = $this->rows($context);
        $this->assertSame(['turn.audit_after_failure', 'turn.audit_replay', 'turn.audit_replay'], $rows->pluck('event_type')->all());
        $this->assertSame($this->metadata($rows[1]), $this->metadata($rows[2]));
        $this->assertSame($rows[1]->occurred_at, $rows[2]->occurred_at);
    }

    private function context(World $world, ?TurnRun $run = null): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $run ??= TurnRun::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $ruleset->id,
            'target_turn' => $world->current_turn + 1, 'random_seed' => hash('sha256', 'cell-audit-fixture'),
            'source' => 'manual', 'is_dry_run' => true, 'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);

        return new TurnContext(
            world: $world, run: $run, ruleset: $ruleset, targetTurn: $run->target_turn,
            randomSeed: $run->random_seed, random: new TurnRandomStreamFactory($run->random_seed), state: new TurnState,
        );
    }

    /** @return Collection<int, object> */
    private function rows(TurnContext $context): Collection
    {
        return DB::table('audit_events')->where('world_id', $context->world->id)
            ->where('turn', $context->targetTurn)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function metadata(object $row): array
    {
        return json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
