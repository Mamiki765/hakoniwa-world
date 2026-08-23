<?php

namespace Tests\Feature;

use App\Application\MonsterKillCycleService;
use App\Application\TurnRunner;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\ScaffoldTurnPhase;
use App\Domain\Turn\TurnAlreadyAppliedException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnOrderService;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\TurnState;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Closure;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class TurnRunnerTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const SEED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private const PHASES = TurnPipeline::CANONICAL_PHASE_KEYS;

    public function test_dry_run_records_snapshot_without_changing_game_state_or_ruleset(): void
    {
        $world = $this->lightweightWorld();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $rulesetSnapshot = $ruleset->settings;
        $worldSnapshot = $world->only(['name', 'current_turn', 'ruleset_version_id']);

        $run = app(TurnRunner::class)->run($world, true, 'manual');

        $this->assertSame(TurnRun::STATUS_DRY_RUN, $run->status);
        $this->assertSame(2, $run->target_turn);
        $this->assertSame($world->ruleset_version_id, $run->ruleset_version_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run->random_seed);
        $this->assertSame(self::PHASES, collect($run->pipeline)->pluck('key')->all());
        $this->assertSame([], $run->failure_context['missing_phases']);
        $this->assertTrue($run->failure_context['pipeline_validation']['valid']);
        $this->assertSame($worldSnapshot, $world->fresh()->only(array_keys($worldSnapshot)));
        $this->assertSame($rulesetSnapshot, $ruleset->fresh()->settings);
    }

    public function test_complete_default_pipeline_commits_and_advances_the_world_turn(): void
    {
        $world = $this->lightweightWorld();

        $run = app(TurnRunner::class)->run($world);

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->failure_code);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame(self::PHASES, collect($run->phase_results)->pluck('phase')->all());
        $completedEvent = DB::table('audit_events')->where('event_type', 'turn.completed')->sole();
        $completedMetadata = json_decode((string) $completedEvent->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('admin', $completedEvent->visibility);
        $this->assertArrayNotHasKey('random_seed', $completedMetadata);
        $this->assertSame($run->random_seed, $run->fresh()->random_seed);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
    }

    /**
     * @param  list<string>  $phaseKeys
     */
    #[DataProvider('invalidPipelineProvider')]
    public function test_invalid_pipeline_shape_is_blocked_before_any_phase_runs(
        array $phaseKeys,
        string $diagnosticKey,
    ): void {
        $world = $this->lightweightWorld();
        $observed = [];
        $pipeline = $this->pipelineForKeys(
            $phaseKeys,
            static function (TurnContext $context, string $phase) use (&$observed): void {
                $observed[] = $phase;
            },
        );

        $run = $this->runner($pipeline)->run($world);

        $this->assertSame(TurnRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('pipeline_invalid', $run->failure_code);
        $this->assertSame(self::PHASES, $run->failure_context['expected_phase_order']);
        $this->assertSame($phaseKeys, $run->failure_context['actual_phase_order']);
        $this->assertNotEmpty($run->failure_context[$diagnosticKey]);
        $this->assertSame([], $run->phase_results);
        $this->assertSame([], $observed);
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidPipelineProvider(): array
    {
        $missing = array_values(array_filter(
            self::PHASES,
            static fn (string $key): bool => $key !== 'global_disasters',
        ));
        $swapped = self::PHASES;
        [$swapped[7], $swapped[8]] = [$swapped[8], $swapped[7]];
        $duplicated = self::PHASES;
        array_splice($duplicated, 8, 0, ['global_disasters']);
        $unexpected = self::PHASES;
        array_splice($unexpected, 8, 0, ['unknown_phase']);

        return [
            'missing canonical phase' => [$missing, 'missing_phases'],
            'canonical phases swapped' => [$swapped, 'out_of_order_phases'],
            'canonical phase duplicated' => [$duplicated, 'duplicated_phases'],
            'unknown phase added' => [$unexpected, 'unexpected_phases'],
        ];
    }

    public function test_canonical_phase_cannot_be_downgraded_to_optional(): void
    {
        $world = $this->lightweightWorld();
        $observed = [];
        $pipeline = new TurnPipeline(array_map(
            static fn (string $key): TurnPhase => $key === 'global_disasters'
                ? new ScaffoldTurnPhase($key, false, required: false)
                : new RecordingTurnPhase(
                    $key,
                    static function (TurnContext $context, string $phase) use (&$observed): void {
                        $observed[] = $phase;
                    },
                ),
            self::PHASES,
        ));

        $run = $this->runner($pipeline)->run($world);

        $this->assertSame(TurnRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('pipeline_invalid', $run->failure_code);
        $this->assertSame(['global_disasters'], $run->failure_context['non_required_phases']);
        $this->assertSame([], $run->phase_results);
        $this->assertSame([], $observed);
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    public function test_complete_pipeline_runs_in_source_order_and_advances_only_after_all_phases(): void
    {
        $world = $this->lightweightWorld();
        $observed = [];
        $runner = $this->runner($this->pipeline(function (TurnContext $context, string $phase) use (&$observed): void {
            $this->assertSame(1, $context->world->current_turn);
            $this->assertSame(2, $context->targetTurn);
            $this->assertSame(self::SEED, $context->randomSeed);
            $this->assertInstanceOf(TurnRandomStreamFactory::class, $context->random);
            $this->assertSame(self::SEED, $context->random->masterSeed);
            $this->assertInstanceOf(TurnState::class, $context->state);
            $observed[] = $phase;
        }));

        $run = $runner->run($world);

        $this->assertSame(self::PHASES, $observed);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame(self::PHASES, collect($run->phase_results)->pluck('phase')->all());
    }

    public function test_phase_failure_rolls_back_state_records_failure_and_retries_same_run_and_seed(): void
    {
        $world = $this->lightweightWorld();
        $originalName = $world->name;
        $failing = $this->pipeline(function (TurnContext $context, string $phase): void {
            if ($phase !== 'development_commands') {
                return;
            }

            $context->world->update(['name' => 'must roll back']);
            throw new RuntimeException('synthetic phase failure');
        });

        try {
            $this->runner($failing)->run($world);
            $this->fail('Expected the phase failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic phase failure', $exception->getMessage());
        }

        $failed = TurnRun::query()->where('world_id', $world->id)->where('is_dry_run', false)->firstOrFail();
        $this->assertSame(TurnRun::STATUS_FAILED, $failed->status);
        $this->assertSame('development_commands', $failed->failure_context['phase']);
        $this->assertSame(self::SEED, $failed->random_seed);
        $this->assertSame($originalName, $world->fresh()->name);
        $this->assertSame(1, $world->fresh()->current_turn);

        $completed = $this->runner($this->pipeline())->run($world->fresh(), source: 'manual');
        $this->assertSame($failed->id, $completed->id);
        $this->assertSame(self::SEED, $completed->random_seed);
        $this->assertSame(2, $completed->attempt_count);
        $this->assertSame('manual', $completed->source);
        $this->assertSame(2, $world->fresh()->current_turn);
    }

    public function test_cron_does_not_retry_failed_turn_run(): void
    {
        $world = $this->lightweightWorld();
        $failing = $this->pipeline(function (TurnContext $context, string $phase): void {
            if ($phase === 'development_commands') {
                throw new RuntimeException('synthetic cron failure');
            }
        });

        try {
            $this->runner($failing)->run($world, source: 'cron');
            $this->fail('Expected the cron turn to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic cron failure', $exception->getMessage());
        }

        $failed = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('is_dry_run', false)
            ->firstOrFail();

        $before = $failed->only([
            'status',
            'attempt_count',
            'random_seed',
            'ruleset_version_id',
            'source',
        ]);

        try {
            $this->runner($this->pipeline())->run($world->fresh(), source: 'cron');
            $this->fail('Expected cron retry rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Cron cannot retry unresolved World', $exception->getMessage());
        }

        $after = TurnRun::query()->findOrFail($failed->id);

        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame(TurnRun::STATUS_FAILED, $after->status);
        $this->assertSame(1, $after->attempt_count);
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    public function test_cron_does_not_retry_blocked_turn_run(): void
    {
        $world = $this->lightweightWorld();
        $phaseKeys = array_values(array_filter(
            self::PHASES,
            static fn (string $key): bool => $key !== 'global_disasters',
        ));

        $blocked = $this->runner($this->pipelineForKeys($phaseKeys))
            ->run($world, source: 'cron');

        $this->assertSame(TurnRun::STATUS_BLOCKED, $blocked->status);

        $before = $blocked->only([
            'status',
            'attempt_count',
            'random_seed',
            'ruleset_version_id',
            'source',
        ]);

        try {
            $this->runner($this->pipeline())->run($world->fresh(), source: 'cron');
            $this->fail('Expected cron retry rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Cron cannot retry unresolved World', $exception->getMessage());
        }

        $after = TurnRun::query()->findOrFail($blocked->id);

        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame(TurnRun::STATUS_BLOCKED, $after->status);
        $this->assertSame(1, $after->attempt_count);
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    public function test_retry_reconstructs_random_orders_and_discards_failed_attempt_state(): void
    {
        $world = $this->lightweightWorld();
        foreach (['One', 'Two', 'Three', 'Four'] as $index => $name) {
            Nation::query()->create([
                'world_id' => $world->id, 'nation_number' => $index + 1, 'name' => $name,
                'state' => 'active', 'idle_counter' => 0,
            ]);
        }
        $orders = app(TurnOrderService::class);
        $attemptOrders = [];
        $attemptStates = [];
        $failFirstAttempt = true;
        $pipeline = $this->pipeline(
            function (TurnContext $context, string $phase) use (
                $orders,
                &$attemptOrders,
                &$attemptStates,
                &$failFirstAttempt,
            ): void {
                if ($phase === 'prepare_turn') {
                    $attemptOrders[] = [
                        'nations' => $orders->shuffledNationIds($context->world, $context->random),
                        'cells' => $orders->shuffledSurfaceCellIds($context->world, $context->random),
                    ];
                    $attemptStates[] = $context->state;
                }
                if ($phase === 'development_commands') {
                    $context->state->registerLaunchIntent(1, 'future_missile', 4, 5, 3);
                    if ($failFirstAttempt) {
                        $failFirstAttempt = false;
                        throw new RuntimeException('fail after turn state mutation');
                    }
                }
            },
        );

        try {
            $this->runner($pipeline)->run($world);
            $this->fail('Expected first attempt failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fail after turn state mutation', $exception->getMessage());
        }

        $run = $this->runner($pipeline)->run($world->fresh());

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->attempt_count);
        $this->assertSame(self::SEED, $run->random_seed);
        $this->assertCount(2, $attemptOrders);
        $this->assertSame($attemptOrders[0], $attemptOrders[1]);
        $this->assertNotSame($attemptStates[0], $attemptStates[1]);
        $this->assertCount(1, $attemptStates[0]->launchIntents());
        $this->assertCount(1, $attemptStates[1]->launchIntents());
    }

    public function test_completed_world_target_cannot_be_applied_twice(): void
    {
        $world = $this->lightweightWorld();
        $runner = $this->runner($this->pipeline());
        $runner->run($world);
        $world->fresh()->update(['current_turn' => 1]);

        $this->expectException(TurnAlreadyAppliedException::class);
        $runner->run($world->fresh());
    }

    public function test_postgresql_lock_rejects_same_world_while_different_world_remains_independent(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific advisory lock test.');
        }

        $first = $this->lightweightWorld();
        $second = World::query()->create([
            'key' => 'second-world',
            'name' => 'Second World',
            'ruleset_version_id' => $first->ruleset_version_id,
            'current_turn' => 1,
        ]);
        $connectionName = 'pgsql-turn-lock-probe';
        config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);
        $key = "hakoniwa.turn.world.{$first->id}";

        try {
            $acquired = DB::connection($connectionName)->selectOne(
                'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
                [$key],
            );
            $this->assertTrue($acquired->acquired);

            try {
                app(TurnRunner::class)->run($first, true);
                $this->fail('Expected same-World overlap rejection.');
            } catch (TurnAlreadyRunningException) {
                $this->assertSame(0, TurnRun::query()->where('world_id', $first->id)->count());
            }

            $otherRun = app(TurnRunner::class)->run($second, true);
            $this->assertSame(TurnRun::STATUS_DRY_RUN, $otherRun->status);
        } finally {
            DB::connection($connectionName)->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$key],
            );
            DB::purge($connectionName);
        }
    }

    public function test_artisan_manual_and_cron_invocations_use_the_same_runner_and_status_history(): void
    {
        $world = $this->lightweightWorld();

        $this->artisan('hakoniwa:turn:run', [
            '--world' => $world->key,
            '--dry-run' => true,
            '--source' => 'manual',
        ])->assertSuccessful();
        $this->artisan('hakoniwa:turn:run', [
            '--world' => $world->key,
            '--dry-run' => true,
            '--source' => 'cron',
        ])->assertSuccessful();
        $this->artisan('hakoniwa:turn:status', ['--world' => $world->key])
            ->expectsOutputToContain('current_turn=1')
            ->assertSuccessful();

        $this->assertSame(['manual', 'cron'], TurnRun::query()->orderBy('id')->pluck('source')->all());
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    private function runner(TurnPipeline $pipeline): TurnRunner
    {
        return new TurnRunner(
            $pipeline,
            new WorldMutationLock,
            new FixedTurnSeedGenerator(self::SEED),
            app(CurrentRulesetGuard::class),
            app(MonsterKillCycleService::class),
        );
    }

    private function pipeline(?Closure $effect = null): TurnPipeline
    {
        return $this->pipelineForKeys(self::PHASES, $effect);
    }

    /**
     * @param  list<string>  $phaseKeys
     */
    private function pipelineForKeys(array $phaseKeys, ?Closure $effect = null): TurnPipeline
    {
        return new TurnPipeline(array_map(
            static fn (string $key): TurnPhase => new RecordingTurnPhase($key, $effect),
            $phaseKeys,
        ));
    }
}

final class RecordingTurnPhase implements TurnPhase
{
    public function __construct(
        private readonly string $phaseKey,
        private readonly ?Closure $effect = null,
    ) {}

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
        if ($this->effect !== null) {
            ($this->effect)($context, $this->phaseKey);
        }

        return new TurnPhaseResult($this->phaseKey, ['tested' => true]);
    }
}

final readonly class FixedTurnSeedGenerator implements TurnSeedGenerator
{
    public function __construct(private string $seed) {}

    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return $this->seed;
    }
}
