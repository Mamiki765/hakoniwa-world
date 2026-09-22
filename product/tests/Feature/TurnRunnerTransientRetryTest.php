<?php

namespace Tests\Feature;

use App\Application\MonsterRemovalService;
use App\Application\TurnRunner;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use Closure;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class TurnRunnerTransientRetryTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    public function test_nested_deadlock_and_serialization_failure_restart_one_turn_with_fresh_state(): void
    {
        $world = $this->lightweightWorld();
        $originalName = $world->name;
        $cell = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)->firstOrFail();
        app(MapCellStateService::class)->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail());
        $cell->save();
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id, 'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp, 'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive', 'spawned_target_turn' => $world->current_turn, 'version' => 1,
        ]);
        MonsterOccupancy::query()->create(['monster_instance_id' => $monster->id, 'map_cell_id' => $cell->id]);
        $removal = app(MonsterRemovalService::class);
        $observed = [];
        $states = [];
        $runner = $this->runner(function (TurnContext $context, string $phase) use ($cell, $removal, &$observed, &$states): void {
            if ($phase === 'prepare_turn') {
                $this->assertSame(0, $removal->removedCount());
                $this->assertTrue($removal->hasAtCell($context, $cell->id));
                $observed[] = [
                    $context->run->id, $context->targetTurn, $context->ruleset->id,
                    $context->randomSeed, $context->random->stream('retry-fixture')->integer(0, 1_000_000),
                ];
                $states[] = $context->state;
                $context->world->update(['name' => $context->world->name.'!']);
                $removal->beginWorld($context);
                $this->assertTrue($removal->removeAtCell($context, $cell, 'terrain_event'));
            }
            if ($phase === 'finalize_turn' && $context->run->attempt_count === 1) {
                // Exercise Laravel's nested DeadlockException wrapper as well
                // as real PostgreSQL rollback, without sleeps or timing races.
                DB::transaction(static function (): void {
                    DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'retry fixture' USING ERRCODE = '40P01'; END; $$;");
                });
            }
            if ($phase === 'finalize_turn' && $context->run->attempt_count === 2) {
                DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'retry fixture' USING ERRCODE = '40001'; END; $$;");
            }
        });

        $run = $runner->run($world, source: 'cron');

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, $run->attempt_count);
        $this->assertSame(['40P01', '40001'], array_column($run->failure_context['transient_retries'], 'sqlstate'));
        $this->assertSame([1, 2], array_column($run->failure_context['transient_retries'], 'attempt_count'));
        $this->assertSame($observed[0], $observed[1]);
        $this->assertSame($observed[0], $observed[2]);
        $this->assertNotSame($states[0], $states[1]);
        $this->assertNotSame($states[1], $states[2]);
        $this->assertSame($world->current_turn + 1, $world->fresh()->current_turn);
        $this->assertSame($originalName.'!', $world->fresh()->name);
        $this->assertDatabaseCount('turn_runs', 1);
        $this->assertSame('removed', $monster->fresh()->state);
        $this->assertSame(1, $removal->removedCount());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'monster.removed_by_terrain_event')->count());
    }

    public function test_retry_exhaustion_keeps_cron_blocked_and_manual_recovery_reuses_the_run(): void
    {
        $world = $this->lightweightWorld();
        $originalName = $world->name;
        $fail = true;
        $executions = 0;
        $runner = $this->runner(function (TurnContext $context, string $phase) use (&$fail, &$executions): void {
            if ($phase === 'prepare_turn') {
                $executions++;
                $context->world->update(['name' => 'retry must roll back']);
                if ($fail) {
                    DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'retry fixture' USING ERRCODE = '40001'; END; $$;");
                }
            }
        });
        try {
            $runner->run($world, source: 'manual');
            $this->fail('Expected transient retries to stop.');
        } catch (QueryException $exception) {
            $this->assertSame('40001', $exception->errorInfo[0]);
        }
        $failed = TurnRun::query()->where('world_id', $world->id)->sole();
        $this->assertSame(TurnRun::STATUS_FAILED, $failed->status);
        $this->assertSame(3, $executions);
        $this->assertSame(3, $failed->attempt_count);
        $this->assertSame('prepare_turn', $failed->failure_context['phase']);
        $this->assertSame('40001', $failed->failure_context['sqlstate']);
        $this->assertSame(3, $failed->failure_context['attempts_this_invocation']);
        $this->assertSame($originalName, $world->fresh()->name);
        $this->assertSame($world->current_turn, $world->fresh()->current_turn);

        try {
            $runner->run($world->fresh(), source: 'cron');
            $this->fail('C1 must not enable cross-invocation cron retry.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Cron cannot retry unresolved World', $exception->getMessage());
        }
        $this->assertSame(3, $failed->fresh()->attempt_count);
        $this->assertSame(3, $executions);

        $fail = false;
        $completed = $runner->run($world->fresh(), source: 'manual');
        $this->assertSame($failed->id, $completed->id);
        $this->assertSame($failed->random_seed, $completed->random_seed);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $completed->status);
        $this->assertSame(4, $completed->attempt_count);
        $this->assertSame($world->current_turn + 1, $world->fresh()->current_turn);
    }

    public function test_non_retryable_sqlstate_is_not_retried_from_a_deadlock_message(): void
    {
        $world = $this->lightweightWorld();
        $executions = 0;
        $runner = $this->runner(function (TurnContext $context, string $phase) use (&$executions): void {
            if ($phase === 'prepare_turn') {
                $executions++;
                DB::transaction(static function (): void {
                    DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected 40P01' USING ERRCODE = '23505'; END; $$;");
                });
            }
        });
        try {
            $runner->run($world, source: 'cron');
            $this->fail('A non-retryable SQLSTATE must fail immediately.');
        } catch (QueryException|DeadlockException) {
            $failed = TurnRun::query()->where('world_id', $world->id)->sole();
            $this->assertSame(1, $executions);
            $this->assertSame(1, $failed->attempt_count);
            $this->assertSame('23505', $failed->failure_context['sqlstate']);
            $this->assertSame(TurnRun::STATUS_FAILED, $failed->status);
            $this->assertSame($world->current_turn, $world->fresh()->current_turn);
        }
    }

    private function runner(Closure $effect): TurnRunner
    {
        $pipeline = new TurnPipeline(array_map(
            static fn (string $key): TurnPhase => new class($key, $effect) implements TurnPhase
            {
                public function __construct(private readonly string $phaseKey, private readonly Closure $effect) {}

                public function key(): string
                {
                    return $this->phaseKey;
                }

                public function required(): bool
                {
                    return true;
                }

                public function implemented(): bool
                {
                    return true;
                }

                public function execute(TurnContext $context): TurnPhaseResult
                {
                    ($this->effect)($context, $this->phaseKey);

                    return new TurnPhaseResult($this->phaseKey, ['tested' => true]);
                }
            },
            TurnPipeline::CANONICAL_PHASE_KEYS,
        ));

        return app()->make(TurnRunner::class, ['pipeline' => $pipeline]);
    }
}
