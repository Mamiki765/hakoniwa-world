<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Domain\Turn\TurnAlreadyAppliedException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\WorldTurnLock;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TurnRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const SEED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private const PHASES = TurnPipeline::CANONICAL_PHASE_KEYS;

    public function test_dry_run_records_snapshot_without_changing_game_state_or_ruleset(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $rulesetSnapshot = $ruleset->settings;
        $worldSnapshot = $world->only(['name', 'current_turn', 'ruleset_version_id']);

        $run = app(TurnRunner::class)->run($world, true, 'manual');

        $this->assertSame(TurnRun::STATUS_DRY_RUN, $run->status);
        $this->assertSame(1, $run->target_turn);
        $this->assertSame($world->ruleset_version_id, $run->ruleset_version_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run->random_seed);
        $this->assertSame(self::PHASES, collect($run->pipeline)->pluck('key')->all());
        $this->assertNotEmpty($run->failure_context['missing_phases']);
        $this->assertSame($worldSnapshot, $world->fresh()->only(array_keys($worldSnapshot)));
        $this->assertSame($rulesetSnapshot, $ruleset->fresh()->settings);
    }

    public function test_incomplete_default_pipeline_records_block_and_never_advances_production_turn(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();

        $run = app(TurnRunner::class)->run($world);

        $this->assertSame(TurnRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('pipeline_incomplete', $run->failure_code);
        $this->assertSame(0, $world->fresh()->current_turn);
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
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame(0, $world->fresh()->current_turn);
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
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame(0, $world->fresh()->current_turn);
    }

    public function test_complete_pipeline_runs_in_source_order_and_advances_only_after_all_phases(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $observed = [];
        $runner = $this->runner($this->pipeline(function (TurnContext $context, string $phase) use (&$observed): void {
            $this->assertSame(0, $context->world->current_turn);
            $this->assertSame(1, $context->targetTurn);
            $this->assertSame(self::SEED, $context->randomSeed);
            $observed[] = $phase;
        }));

        $run = $runner->run($world);

        $this->assertSame(self::PHASES, $observed);
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $world->fresh()->current_turn);
        $this->assertSame(self::PHASES, collect($run->phase_results)->pluck('phase')->all());
    }

    public function test_phase_failure_rolls_back_state_records_failure_and_retries_same_run_and_seed(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
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
        $this->assertSame(0, $world->fresh()->current_turn);

        $completed = $this->runner($this->pipeline())->run($world->fresh(), source: 'cron');
        $this->assertSame($failed->id, $completed->id);
        $this->assertSame(self::SEED, $completed->random_seed);
        $this->assertSame(2, $completed->attempt_count);
        $this->assertSame('cron', $completed->source);
        $this->assertSame(1, $world->fresh()->current_turn);
    }

    public function test_completed_world_target_cannot_be_applied_twice(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $runner = $this->runner($this->pipeline());
        $runner->run($world);
        $world->fresh()->update(['current_turn' => 0]);

        $this->expectException(TurnAlreadyAppliedException::class);
        $runner->run($world->fresh());
    }

    public function test_postgresql_lock_rejects_same_world_while_different_world_remains_independent(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific advisory lock test.');
        }

        $first = app(OceanWorldGenerator::class)->initialize();
        $second = World::query()->create([
            'key' => 'second-world',
            'name' => 'Second World',
            'ruleset_version_id' => $first->ruleset_version_id,
            'current_turn' => 0,
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
        $world = app(OceanWorldGenerator::class)->initialize();

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
            ->expectsOutputToContain('current_turn=0')
            ->assertSuccessful();

        $this->assertSame(['manual', 'cron'], TurnRun::query()->orderBy('id')->pluck('source')->all());
        $this->assertSame(0, $world->fresh()->current_turn);
    }

    private function runner(TurnPipeline $pipeline): TurnRunner
    {
        return new TurnRunner($pipeline, new WorldTurnLock, new FixedTurnSeedGenerator(self::SEED));
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
