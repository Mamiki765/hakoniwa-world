<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TerritoryInfluenceService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\WorldGenerationProfile;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\NationCapital;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TerritoryInfluencePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_60_by_60_influence_pass_has_bounded_queries_and_runtime(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Production);
        $first = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '領地感化負荷試験国',
            '負荷試験島主',
        );
        $second = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '領地感化隣接国',
            '隣接試験島主',
        );
        $third = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '領地感化第三国',
            '第三試験島主',
        );
        $surfaceSpaceId = $world->mapSpaces()->where('key', 'surface')->value('id');
        $surfaceCellIds = MapCell::query()->where('map_space_id', $surfaceSpaceId)
            ->orderBy('id')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertCount(3_600, $surfaceCellIds);

        $forestId = TerrainDefinition::query()->where('key', 'forest')->value('id');
        $plainId = TerrainDefinition::query()->where('key', 'plain')->value('id');
        $capitalId = FacilityDefinition::query()->where('key', 'capital')->value('id');
        DB::update(
            'UPDATE map_cells SET terrain_definition_id = ?, facility_definition_id = NULL, '
            .'monument_definition_id = NULL, '
            .'owner_nation_id = CASE MOD(x + y, 3) '
            .'WHEN 0 THEN CAST(? AS BIGINT) WHEN 1 THEN CAST(? AS BIGINT) ELSE CAST(? AS BIGINT) END, '
            .'population = 0, terrain_quantity = NULL, facility_scale = NULL, '
            .'facility_experience = NULL, facility_operational_state = NULL '
            .'WHERE map_space_id = ?',
            [$forestId, $first->id, $second->id, $third->id, $surfaceSpaceId],
        );
        $capitals = NationCapital::query()
            ->whereIn('nation_id', [$first->id, $second->id, $third->id])
            ->get();
        foreach ($capitals as $capital) {
            MapCell::query()->whereKey($capital->map_cell_id)->update([
                'terrain_definition_id' => $plainId,
                'facility_definition_id' => $capitalId,
                'owner_nation_id' => $capital->nation_id,
                'population' => 10_000,
            ]);
        }

        $ruleset = $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 2,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => hash('sha256', 'territory-influence-production-60x60'),
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds([$first->id, $second->id, $third->id]);
        $state->setDevelopmentNationIds([$first->id, $second->id, $third->id]);
        $state->setSurfaceCellIds($surfaceCellIds);
        $seed = (string) $run->random_seed;
        $context = new TurnContext(
            $world,
            $run,
            $ruleset,
            2,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $started = hrtime(true);
        $metrics = app(TerritoryInfluenceService::class)->execute($context);
        $durationMs = (hrtime(true) - $started) / 1_000_000;
        $queryCount = count($queries);

        $this->assertSame(3_600, $metrics['processed']);
        $this->assertGreaterThan(3_000, $metrics['direction_draws']);
        $this->assertGreaterThan(1_000, $metrics['mutations']);
        $this->assertLessThanOrEqual(20, $queryCount);
        $this->assertLessThan(5_000.0, $durationMs);
        $this->assertSame(
            $metrics['mutations'],
            DB::table('audit_events')->where('event_type', 'territory.influenced')->count(),
        );
    }
}
