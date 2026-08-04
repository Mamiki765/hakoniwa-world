<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class MonsterPerformanceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_debug_32_by_32_no_monster_turn_has_no_per_cell_query_growth(): void
    {
        $this->app->bind(TurnSeedGenerator::class, fn () => new Pr21MonsterPerformanceSeedGenerator);
        $world = $this->lightweightWorld();

        $baseline = $this->measuredTurn($world);
        $this->assertSame(0, $baseline['metrics']['monsters_loaded']);
        $this->assertSame(0, $baseline['metrics']['monster_actions']);
        $this->assertLessThan(100, $baseline['queries']);
        $this->assertLessThan(5_000.0, $baseline['duration_ms']);
        $this->assertMonsterPhaseMetricSchema($baseline['metrics']);

        $this->report('32x32-no-monster', null, $baseline);
    }

    public function test_debug_32_by_32_monster_turn_batch_loads_actors_with_bounded_queries(): void
    {
        $this->app->bind(TurnSeedGenerator::class, fn () => new Pr21MonsterPerformanceSeedGenerator);
        $world = $this->lightweightWorld();

        $this->placeBlockedMonsters($world, 16, 2);
        $withMonsters = $this->measuredTurn($world);

        $this->assertSame(16, $withMonsters['metrics']['monsters_loaded']);
        $this->assertSame(16, $withMonsters['metrics']['monster_actions']);
        $this->assertSame(0, $withMonsters['metrics']['monster_moves']);
        $this->assertSame(0, $withMonsters['metrics']['maximum_moves_by_single_monster']);
        $this->assertLessThan(150, $withMonsters['queries']);
        $this->assertLessThan(5_000.0, $withMonsters['duration_ms']);
        $this->assertMonsterPhaseMetricSchema($withMonsters['metrics']);

        $this->report('32x32-monster', null, $withMonsters);
    }

    public function test_standard_60_by_60_monster_turn_stays_within_query_and_duration_budget(): void
    {
        $this->app->bind(TurnSeedGenerator::class, fn () => new Pr21MonsterPerformanceSeedGenerator);
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Production);
        $this->placeBlockedMonsters($world, 32, 2);

        $measurement = $this->measuredTurn($world);

        $this->assertSame(3_600, $measurement['metrics']['processed']);
        $this->assertSame(32, $measurement['metrics']['monsters_loaded']);
        $this->assertSame(32, $measurement['metrics']['monster_actions']);
        $this->assertLessThan(650, $measurement['queries']);
        $this->assertLessThan(12_000.0, $measurement['duration_ms']);
        $this->assertMonsterPhaseMetricSchema($measurement['metrics']);

        $this->report('60x60', null, $measurement);
    }

    /** @return array{queries: int, duration_ms: float, metrics: array<string, mixed>} */
    private function measuredTurn(World $world): array
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $run = app(TurnRunner::class)->run($world);
        $phase = collect($run->phase_results)->firstWhere('phase', 'process_cells');
        $this->assertIsArray($phase);

        return [
            'queries' => count($queries),
            'duration_ms' => (float) $phase['duration_ms'],
            'metrics' => $phase['metrics'],
        ];
    }

    private function placeBlockedMonsters(World $world, int $count, int $targetTurn): void
    {
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')
            ->firstOrFail();
        $wasteland = TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
        $cells = MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->orderBy('y')->orderBy('x')->limit($count)->get();
        $this->assertCount($count, $cells);

        foreach ($cells as $cell) {
            $cell->update(['terrain_definition_id' => $wasteland->id]);
            $monster = MonsterInstance::query()->create([
                'world_id' => $world->id,
                'monster_definition_id' => $definition->id,
                'current_hp' => $definition->base_hp,
                'spawned_max_hp' => $definition->base_hp,
                'state' => 'alive',
                'spawned_target_turn' => $targetTurn,
                'version' => 1,
            ]);
            MonsterOccupancy::query()->create([
                'monster_instance_id' => $monster->id,
                'map_cell_id' => $cell->id,
            ]);
        }
    }

    /** @param array<string, mixed> $metrics */
    private function assertMonsterPhaseMetricSchema(array $metrics): void
    {
        foreach ([
            'monsters_loaded', 'monster_actions', 'monster_moves', 'cells_trampled',
            'defense_self_destructs', 'damage_blocked', 'monsters_killed',
            'rewards_distributed', 'kill_records_created', 'maximum_moves_by_single_monster',
        ] as $key) {
            $this->assertArrayHasKey($key, $metrics);
        }
    }

    /**
     * @param  array{queries: int, duration_ms: float, metrics: array<string, mixed>}|null  $baseline
     * @param  array{queries: int, duration_ms: float, metrics: array<string, mixed>}  $measurement
     */
    private function report(string $profile, ?array $baseline, array $measurement): void
    {
        if (getenv('REPORT_MONSTER_PERFORMANCE') !== '1') {
            return;
        }
        fwrite(STDERR, json_encode([
            'profile' => $profile,
            'baseline_queries' => $baseline['queries'] ?? null,
            'baseline_process_cells_ms' => $baseline['duration_ms'] ?? null,
            'monster_queries' => $measurement['queries'],
            'monster_process_cells_ms' => $measurement['duration_ms'],
            'monsters_loaded' => $measurement['metrics']['monsters_loaded'],
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

final readonly class Pr21MonsterPerformanceSeedGenerator implements TurnSeedGenerator
{
    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return str_repeat('0', 64);
    }
}
