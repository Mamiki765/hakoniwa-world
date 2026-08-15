<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\MonsterKillCycleService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Application\WorldExpansionService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldGenerationProfile;
use App\Domain\World\WorldMutationLock;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class TurnRuntimePerformanceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    #[DataProvider('expandedWorldProfiles')]
    public function test_expanded_empty_world_turn_has_bounded_phase_queries(
        string $profile,
        int $expectedCells,
    ): void {
        $world = $this->expandedWorld($expectedCells);

        $measurement = $this->measureTurn($world);
        $processCells = $measurement['phases']['process_cells'];
        $globalDisasters = $measurement['phases']['global_disasters'];

        $this->report($profile, $measurement);
        $this->assertSame($expectedCells, $processCells['metrics']['processed']);
        $this->assertLessThanOrEqual(12, $processCells['queries']);
        $this->assertLessThanOrEqual(70, $globalDisasters['queries']);
        $this->assertLessThanOrEqual(64, $globalDisasters['hydrated_models'][MapCell::class] ?? 0);
        $this->assertSame(TurnPipeline::CANONICAL_PHASE_KEYS, array_keys($measurement['phases']));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function expandedWorldProfiles(): iterable
    {
        // Keep the rectangular production expansion state plus the smallest and largest square bounds.
        // The intermediate 80x80 square does not add a distinct size or shape regression signal.
        yield '64x64' => ['64x64', 4_096];
        yield '80x64' => ['80x64', 5_120];
        yield '96x96' => ['96x96', 9_216];
    }

    public function test_settlement_candidate_heavy_64_by_64_world_has_bounded_process_cell_queries(): void
    {
        $world = $this->expandedWorld(4_096);
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'Performance Nation',
            'Performance Owner',
        );
        $space = $this->surfaceMapSpace($world);
        $capitalCellId = (int) $nation->capital()->valueOrFail('map_cell_id');
        $plainId = (int) TerrainDefinition::query()->where('key', 'plain')->valueOrFail('id');
        $wheatId = (int) ResourceDefinition::query()->where('key', 'wheat')->valueOrFail('id');
        NationResource::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $wheatId)
            ->update(['amount' => 1_000_000]);
        MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereKeyNot($capitalCellId)
            ->update([
                'terrain_definition_id' => $plainId,
                'facility_definition_id' => null,
                'monument_definition_id' => null,
                'owner_nation_id' => $nation->id,
                'population' => 0,
                'terrain_quantity' => null,
                'facility_scale' => null,
                'facility_experience' => null,
                'facility_operational_state' => null,
            ]);

        $measurement = $this->measureTurn($world);

        $this->report('64x64-settlement-heavy', $measurement);
        $this->assertSame(4_096, $measurement['phases']['process_cells']['metrics']['processed']);
        $this->assertLessThanOrEqual(20, $measurement['phases']['process_cells']['queries']);
    }

    #[DataProvider('nationCountProfiles')]
    public function test_nation_count_profile_reports_phase_scaling(int $nationCount): void
    {
        $world = $this->lightweightWorld();
        for ($index = 1; $index <= $nationCount; $index++) {
            app(NationCreationService::class)->create(
                User::factory()->create(),
                $world,
                "Performance Nation {$index}",
                "Performance Owner {$index}",
            );
        }

        $measurement = $this->measureTurn($world);

        $this->report("32x32-{$nationCount}-nations", $measurement);
        $this->assertSame($nationCount, $measurement['phases']['prepare_turn']['metrics']['nations']);
        $this->assertLessThanOrEqual(19 * $nationCount, $measurement['phases']['nation_economy']['queries']);
        $this->assertLessThanOrEqual(9 * $nationCount, $measurement['phases']['resource_sales']['queries']);
        $this->assertLessThanOrEqual(5 + $nationCount, $measurement['phases']['aggregate_nations']['queries']);
        $this->assertLessThanOrEqual(4 * $nationCount, $measurement['phases']['enforce_capacities']['queries']);
    }

    public function test_population_eligible_natural_spawn_does_not_rehydrate_the_full_world(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'Spawn Performance Nation',
            'Spawn Performance Owner',
        );
        $space = $this->surfaceMapSpace($world);
        $capitalCellId = (int) $nation->capital()->valueOrFail('map_cell_id');
        $plainId = (int) TerrainDefinition::query()->where('key', 'plain')->valueOrFail('id');
        $villageId = (int) FacilityDefinition::query()->where('key', 'village')->valueOrFail('id');
        $settlementIds = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereKeyNot($capitalCellId)
            ->orderBy('id')
            ->limit(70)
            ->pluck('id');
        $this->assertCount(70, $settlementIds);
        MapCell::query()->whereIn('id', $settlementIds)->update([
            'terrain_definition_id' => $plainId,
            'facility_definition_id' => $villageId,
            'monument_definition_id' => null,
            'owner_nation_id' => $nation->id,
            'population' => 1_600,
            'terrain_quantity' => null,
            'facility_scale' => null,
            'facility_experience' => null,
            'facility_operational_state' => null,
        ]);
        $wheatId = (int) ResourceDefinition::query()->where('key', 'wheat')->valueOrFail('id');
        NationResource::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $wheatId)
            ->update(['amount' => 1_000_000]);

        $measurement = $this->measureTurn($world);
        $globalDisasters = $measurement['phases']['global_disasters'];

        $this->report('32x32-natural-spawn-eligible', $measurement);
        $this->assertSame(1, $globalDisasters['metrics']['eligible_spawn_nations']);
        $this->assertLessThanOrEqual(160, $globalDisasters['hydrated_models'][MapCell::class] ?? 0);
    }

    public function test_multi_resource_sales_do_not_reload_the_nation_per_sale(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'Sale Performance Nation',
            'Sale Performance Owner',
        );
        $nation->update(['money' => 0]);
        $resources = ResourceDefinition::query()->where('tradable', true)->orderBy('id')->get();
        foreach ($resources as $resource) {
            NationResource::query()->updateOrCreate([
                'nation_id' => $nation->id,
                'resource_definition_id' => $resource->id,
            ], ['amount' => 2_500]);
            NationResourceSalePolicy::query()->updateOrCreate([
                'nation_id' => $nation->id,
                'resource_definition_id' => $resource->id,
            ], ['policy' => 'keep_amount', 'keep_amount' => 0]);
        }

        $measurement = $this->measureTurn($world);
        $sales = $measurement['phases']['resource_sales'];

        $this->report('32x32-multi-resource-sales', $measurement);
        $this->assertGreaterThanOrEqual(3, $sales['metrics']['sales']);
        $this->assertLessThanOrEqual(4, $sales['query_types']['select'] ?? 0);
    }

    /** @return iterable<string, array{int}> */
    public static function nationCountProfiles(): iterable
    {
        yield 'one nation' => [1];
        yield 'four nations' => [4];
    }

    private function expandedWorld(int $expectedCells): World
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Production);
        $service = app(WorldExpansionService::class);
        $steps = [
            [new MapBounds(0, 59, 0, 59, 16), new MapBounds(0, 63, 0, 63, 16), 4_096],
            [new MapBounds(0, 63, 0, 63, 16), new MapBounds(-16, 63, 0, 63, 16), 5_120],
            [new MapBounds(-16, 63, 0, 63, 16), new MapBounds(-16, 63, 0, 79, 16), 6_400],
            [new MapBounds(-16, 63, 0, 79, 16), new MapBounds(-16, 79, 0, 79, 16), 7_680],
            [new MapBounds(-16, 79, 0, 79, 16), new MapBounds(-16, 79, -16, 79, 16), 9_216],
        ];
        foreach ($steps as [$before, $target, $cellCount]) {
            $service->expand($world->fresh(), $before, $target);
            if ($cellCount === $expectedCells) {
                return $world->fresh();
            }
        }

        $this->fail("Unsupported expanded World cell count {$expectedCells}.");
    }

    /**
     * @return array{
     *     total_wall_ms: float,
     *     total_queries: int,
     *     phases: array<string, array{
     *         queries: int,
     *         query_time_ms: float,
     *         query_types: array<string, int>,
     *         repeated_queries: list<array{count: int, sql: string}>,
     *         hydrated_models: array<string, int>,
     *         peak_memory_bytes: int,
     *         wall_ms: float,
     *         metrics: array<string, mixed>
     *     }>
     * }
     */
    private function measureTurn(World $world): array
    {
        $probe = new TurnRuntimeProbe;
        DB::listen(static function (QueryExecuted $query) use ($probe): void {
            $probe->recordQuery($query);
        });
        Event::listen('eloquent.retrieved: *', static function (string $event, array $payload) use ($probe): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                $probe->recordHydration($model);
            }
        });
        $engine = app(CompleteTurnEngine::class);
        $pipeline = new TurnPipeline(array_map(
            static fn (string $key): MeasuredGameplayTurnPhase => new MeasuredGameplayTurnPhase(
                $key,
                $engine,
                $probe,
            ),
            TurnPipeline::CANONICAL_PHASE_KEYS,
        ));
        $runner = new TurnRunner(
            $pipeline,
            new WorldMutationLock,
            new TurnRuntimeFixedSeedGenerator,
            app(CurrentRulesetGuard::class),
            app(MonsterKillCycleService::class),
        );

        $probe->start();
        $started = hrtime(true);
        $run = $runner->run($world);
        $wallMs = round((hrtime(true) - $started) / 1_000_000, 3);
        $this->assertSame('completed', $run->status);

        return [
            'total_wall_ms' => $wallMs,
            'total_queries' => $probe->totalQueryCount(),
            'phases' => $probe->phases(),
        ];
    }

    /** @param array<string, mixed> $measurement */
    private function report(string $profile, array $measurement): void
    {
        if (getenv('REPORT_TURN_RUNTIME_PERFORMANCE') !== '1') {
            return;
        }

        fwrite(STDERR, json_encode([
            'profile' => $profile,
            ...$measurement,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

final readonly class MeasuredGameplayTurnPhase implements TurnPhase
{
    public function __construct(
        private string $phaseKey,
        private CompleteTurnEngine $engine,
        private TurnRuntimeProbe $probe,
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
        $this->probe->beginPhase($this->phaseKey);
        $result = $this->engine->execute($this->phaseKey, $context);
        $this->probe->endPhase($result);

        return $result;
    }
}

final class TurnRuntimeProbe
{
    private bool $started = false;

    private ?string $phase = null;

    private int $phaseStartedAt = 0;

    private int $phaseStartMemory = 0;

    /** @var array<string, list<QueryExecuted>> */
    private array $queries = [];

    /** @var array<string, array<class-string<Model>, int>> */
    private array $hydrations = [];

    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    public function start(): void
    {
        $this->started = true;
    }

    public function beginPhase(string $phase): void
    {
        $this->phase = $phase;
        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $this->phaseStartMemory = memory_get_usage(true);
        $this->phaseStartedAt = hrtime(true);
    }

    public function endPhase(TurnPhaseResult $result): void
    {
        $phase = $this->phase;
        if ($phase === null) {
            return;
        }
        $queries = $this->queries[$phase] ?? [];
        $normalized = [];
        $types = [];
        $queryTime = 0.0;
        foreach ($queries as $query) {
            $sql = preg_replace('/\s+/', ' ', strtolower(trim($query->sql))) ?? $query->sql;
            $normalized[$sql] = ($normalized[$sql] ?? 0) + 1;
            $type = strtolower(strtok(ltrim($query->sql), " \t\r\n") ?: 'unknown');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $queryTime += $query->time;
        }
        arsort($normalized);
        $repeated = [];
        foreach ($normalized as $sql => $count) {
            if ($count < 2) {
                continue;
            }
            $repeated[] = ['count' => $count, 'sql' => substr($sql, 0, 300)];
            if (count($repeated) === 5) {
                break;
            }
        }
        $hydrations = $this->hydrations[$phase] ?? [];
        ksort($hydrations);
        ksort($types);
        $this->results[$phase] = [
            'queries' => count($queries),
            'query_time_ms' => round($queryTime, 3),
            'query_types' => $types,
            'repeated_queries' => $repeated,
            'hydrated_models' => $hydrations,
            'peak_memory_bytes' => max(0, memory_get_peak_usage(true) - $this->phaseStartMemory),
            'wall_ms' => round((hrtime(true) - $this->phaseStartedAt) / 1_000_000, 3),
            'metrics' => $result->metrics,
        ];
        $this->phase = null;
    }

    public function recordQuery(QueryExecuted $query): void
    {
        if (! $this->started) {
            return;
        }
        $phase = $this->phase ?? '__runner__';
        $this->queries[$phase][] = $query;
    }

    public function recordHydration(Model $model): void
    {
        if (! $this->started || $this->phase === null) {
            return;
        }
        $class = $model::class;
        $this->hydrations[$this->phase][$class] = ($this->hydrations[$this->phase][$class] ?? 0) + 1;
    }

    public function totalQueryCount(): int
    {
        return array_sum(array_map('count', $this->queries));
    }

    /** @return array<string, array<string, mixed>> */
    public function phases(): array
    {
        return $this->results;
    }
}

final readonly class TurnRuntimeFixedSeedGenerator implements TurnSeedGenerator
{
    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return str_repeat('0', 64);
    }
}
