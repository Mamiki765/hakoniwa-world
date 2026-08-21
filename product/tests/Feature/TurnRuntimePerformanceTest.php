<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\MonsterKillCycleService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Application\WorldExpansionService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\TurnState;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldGenerationProfile;
use App\Domain\World\WorldMutationLock;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

    #[DataProvider('normalProcessCellProfiles')]
    public function test_normal_world_process_cell_profile_reports_query_scaling(
        string $profile,
        int $expectedCells,
    ): void {
        $world = $this->processCellProfileWorld($expectedCells, 'normal');

        $measurement = $this->measureTurn($world);

        $this->report($profile, $measurement);
        $this->assertSame($expectedCells, $measurement['phases']['process_cells']['metrics']['processed']);
        $this->assertGreaterThan(0, $measurement['phases']['process_cells']['queries']);
    }

    #[DataProvider('matureProcessCellProfiles')]
    public function test_mature_world_process_cell_profile_reports_query_scaling(
        string $profile,
        int $expectedCells,
    ): void {
        $world = $this->processCellProfileWorld($expectedCells, 'mature');

        $measurement = $this->measureTurn($world);

        $this->report($profile, $measurement);
        $this->assertSame($expectedCells, $measurement['phases']['process_cells']['metrics']['processed']);
        $this->assertSame(0, $measurement['phases']['process_cells']['coordinate_cell_lookup_queries']);
        $this->assertLessThanOrEqual(40, $measurement['phases']['process_cells']['query_types']['select'] ?? 0);
    }

    #[DataProvider('specialProcessCellProfiles')]
    public function test_special_process_cell_profile_reports_query_shape(string $profile, string $fixture): void
    {
        $world = $this->processCellProfileWorld(1_024, $fixture);

        $measurement = $this->measureTurn($world);

        $this->report($profile, $measurement);
        $this->assertSame(1_024, $measurement['phases']['process_cells']['metrics']['processed']);
        $this->assertGreaterThan(0, $measurement['phases']['process_cells']['queries']);
        $this->assertSame(0, $measurement['phases']['process_cells']['coordinate_cell_lookup_queries']);
    }

    #[DataProvider('forcedDisasterProfiles')]
    public function test_forced_global_disaster_profile_reports_query_shape(string $disasterKey): void
    {
        [$world, $ruleset] = $this->forcedDisasterWorld($disasterKey);
        $seed = $this->forcedDisasterSeed($disasterKey);

        $measurement = $this->measureGlobalDisasters($world, $ruleset, $seed);
        $globalDisasters = $measurement['phases']['global_disasters'];

        $this->report("forced-{$disasterKey}", $measurement);
        $this->assertGreaterThanOrEqual(1, $globalDisasters['metrics']['executed_disasters']);
        $this->assertGreaterThan(0, $globalDisasters['metrics']['damaged_cells']);
        $this->assertGreaterThan(0, $globalDisasters['queries']);
        $this->assertSame(0, $globalDisasters['coordinate_cell_lookup_queries']);
        $this->assertSame(0, $globalDisasters['active_nation_lookup_queries']);
        // Formal v11 adds the Aoi sea/shallow candidate terrain lookup beside the forced disaster.
        $this->assertLessThanOrEqual(2, $globalDisasters['terrain_definition_lookup_queries']);
        $this->assertSame(0, $globalDisasters['monster_occupancy_lookup_queries']);
        $this->assertLessThanOrEqual(20, $globalDisasters['query_types']['select'] ?? 0);
    }

    /** @return iterable<string, array{string, int}> */
    public static function normalProcessCellProfiles(): iterable
    {
        yield '32x32 normal' => ['32x32-normal', 1_024];
        yield '64x64 normal' => ['64x64-normal', 4_096];
        yield '96x96 normal' => ['96x96-normal', 9_216];
    }

    /** @return iterable<string, array{string, int}> */
    public static function matureProcessCellProfiles(): iterable
    {
        yield '32x32 mature' => ['32x32-mature', 1_024];
        yield '64x64 mature' => ['64x64-mature', 4_096];
        yield '96x96 mature' => ['96x96-mature', 9_216];
    }

    /** @return iterable<string, array{string, string}> */
    public static function specialProcessCellProfiles(): iterable
    {
        yield 'fire targets' => ['32x32-fire-target-heavy', 'fire'];
        yield 'fire protection sources' => ['32x32-fire-protection-heavy', 'protection'];
        yield 'famine riot candidates' => ['32x32-famine-riot-heavy', 'famine'];
    }

    /** @return iterable<string, array{string}> */
    public static function forcedDisasterProfiles(): iterable
    {
        yield 'earthquake' => ['earthquake'];
        yield 'tsunami' => ['tsunami'];
        yield 'typhoon' => ['typhoon'];
        yield 'meteor shower' => ['meteor_shower'];
        yield 'huge meteor' => ['huge_meteor'];
        yield 'eruption' => ['eruption'];
        yield 'land subsidence' => ['land_subsidence'];
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

    #[DataProvider('missileShotProfiles')]
    public function test_defense_lookup_queries_do_not_scale_with_missile_shots(int $shots): void
    {
        $world = $this->missileDefenseWorld($shots);

        $measurement = $this->measureTurn($world);
        $processCells = $measurement['phases']['process_cells'];

        $this->report("32x32-defense-{$shots}-shots", $measurement);
        $this->assertSame($shots, $processCells['metrics']['missile_shots_fired']);
        $this->assertLessThanOrEqual(1, $processCells['defense_lookup_queries']);
    }

    /** @return iterable<string, array{int}> */
    public static function missileShotProfiles(): iterable
    {
        yield 'one shot' => [1];
        yield 'five shots' => [5];
        yield 'twenty five shots' => [25];
    }

    /** @return iterable<string, array{int}> */
    public static function nationCountProfiles(): iterable
    {
        yield 'one nation' => [1];
        yield 'four nations' => [4];
    }

    private function missileDefenseWorld(int $shots): World
    {
        $world = $this->lightweightWorld();
        $firingUser = User::factory()->create();
        $firing = app(NationCreationService::class)->create(
            $firingUser,
            $world,
            "Defense Performance {$shots}",
            'Defense Performance Owner',
        );
        $targetNation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "Defense Target {$shots}",
            'Defense Target Owner',
        );
        $firing->update(['money' => 1_000_000]);
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()
            ->where('owner_nation_id', $targetNation->id)
            ->whereKeyNot($targetNation->capital()->valueOrFail('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->with(['terrain', 'facility'])
            ->firstOrFail();
        $defenseCoordinate = collect((new GridCoordinate($target->x, $target->y))->ring(1))
            ->first(static fn (GridCoordinate $coordinate): bool => $coordinate->x >= $space->min_x
                && $coordinate->x <= $space->max_x
                && $coordinate->y >= $space->min_y
                && $coordinate->y <= $space->max_y);
        $this->assertInstanceOf(GridCoordinate::class, $defenseCoordinate);
        $defense = MapCell::query()
            ->where('map_space_id', $space->id)
            ->where('x', $defenseCoordinate->x)
            ->where('y', $defenseCoordinate->y)
            ->with(['terrain', 'facility'])
            ->firstOrFail();
        $states = app(MapCellStateService::class);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        $defenseDefinition = FacilityDefinition::query()->where('key', 'defense')->firstOrFail();
        $missileBaseDefinition = FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail();
        foreach ([$target, $defense] as $cell) {
            $states->setFacility($cell, null);
            $states->transitionTerrain($cell, $plain);
            $cell->owner_nation_id = $targetNation->id;
            $cell->population = 0;
            $cell->save();
        }
        $states->setFacility($defense, $defenseDefinition);
        $defense->save();

        $baseCount = (int) ceil($shots / 5);
        $bases = MapCell::query()
            ->where('owner_nation_id', $firing->id)
            ->whereKeyNot($firing->capital()->valueOrFail('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->limit($baseCount)
            ->get();
        $this->assertCount($baseCount, $bases);
        foreach ($bases as $base) {
            $states->setFacility($base, null);
            $states->transitionTerrain($base, $plain);
            $states->setFacility($base, $missileBaseDefinition, experience: 200);
            $base->save();
        }

        app(CommandQueueService::class)->add(
            user: $firingUser,
            nation: $firing,
            mapSpace: $space,
            commandKey: 'spp_missile',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: (int) ($firing->commandQueue()->value('version') ?? 1),
            quantity: $shots,
            quantityProvided: true,
        );

        return $world;
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

    private function processCellProfileWorld(int $expectedCells, string $fixture): World
    {
        $world = $expectedCells === 1_024 ? $this->lightweightWorld() : $this->expandedWorld($expectedCells);
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "Process Profile {$fixture} {$expectedCells}",
            'Process Profile Owner',
        );
        $nation->update(['money' => 1_000_000]);
        $wheatId = (int) ResourceDefinition::query()->where('key', 'wheat')->valueOrFail('id');
        NationResource::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $wheatId)
            ->update(['amount' => $fixture === 'famine' ? 0 : 1_000_000]);
        if ($fixture === 'normal') {
            return $world;
        }

        $space = $this->surfaceMapSpace($world);
        $capitalCellId = (int) $nation->capital()->valueOrFail('map_cell_id');
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        $forest = TerrainDefinition::query()->where('key', 'forest')->firstOrFail();
        $factory = FacilityDefinition::query()->where('key', 'factory')->firstOrFail();
        $city = FacilityDefinition::query()->where('key', 'city')->firstOrFail();
        $defense = FacilityDefinition::query()->where('key', 'defense')->firstOrFail();
        $cellIds = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereKeyNot($capitalCellId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        MapCell::query()->whereIn('id', $cellIds)->update([
            'terrain_definition_id' => $plain->id,
            'facility_definition_id' => null,
            'monument_definition_id' => null,
            'owner_nation_id' => $nation->id,
            'population' => 0,
            'terrain_quantity' => null,
            'facility_scale' => null,
            'facility_experience' => null,
            'facility_operational_state' => null,
        ]);

        if ($fixture === 'mature') {
            $factoryIds = [];
            $cityIds = [];
            foreach ($cellIds as $index => $cellId) {
                if ($index % 4 === 0) {
                    $factoryIds[] = $cellId;
                } elseif ($index % 4 === 1) {
                    $cityIds[] = $cellId;
                }
            }
            $this->setProfileFacility($factoryIds, $factory->id, 1, 0);
            $this->setProfileFacility($cityIds, $city->id, null, 1_000);
        } elseif ($fixture === 'fire') {
            $this->setProfileFacility($cellIds, $factory->id, 1, 0);
        } elseif ($fixture === 'protection') {
            $factoryIds = [];
            $forestIds = [];
            foreach ($cellIds as $index => $cellId) {
                if ($index % 2 === 0) {
                    $factoryIds[] = $cellId;
                } else {
                    $forestIds[] = $cellId;
                }
            }
            $this->setProfileFacility($factoryIds, $factory->id, 1, 0);
            MapCell::query()->whereIn('id', $forestIds)->update([
                'terrain_definition_id' => $forest->id,
                'terrain_quantity' => $forest->initial_quantity,
            ]);
        } elseif ($fixture === 'famine') {
            $this->setProfileFacility($cellIds, $defense->id, null, 0);
        }

        return $world;
    }

    /** @param list<int> $cellIds */
    private function setProfileFacility(array $cellIds, int $facilityId, ?int $scale, int $population): void
    {
        MapCell::query()->whereIn('id', $cellIds)->update([
            'facility_definition_id' => $facilityId,
            'facility_scale' => $scale,
            'facility_experience' => null,
            'facility_operational_state' => 'operational',
            'population' => $population,
        ]);
    }

    /** @return array{World, RulesetVersion} */
    private function forcedDisasterWorld(string $disasterKey): array
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Production);
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "Forced {$disasterKey}",
            'Forced Disaster Owner',
        );
        $space = $this->surfaceMapSpace($world);
        $capitalCellId = (int) $nation->capital()->valueOrFail('map_cell_id');
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
        $forest = TerrainDefinition::query()->where('key', 'forest')->firstOrFail();
        $factory = FacilityDefinition::query()->where('key', 'factory')->firstOrFail();
        $farm = FacilityDefinition::query()->where('key', 'farm')->firstOrFail();
        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereKeyNot($capitalCellId)
            ->orderBy('id')
            ->get(['id', 'x', 'y']);
        $cellIds = $cells->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        MapCell::query()->whereIn('id', $cellIds)->update([
            'terrain_definition_id' => $plain->id,
            'facility_definition_id' => null,
            'monument_definition_id' => null,
            'owner_nation_id' => $nation->id,
            'population' => 0,
            'terrain_quantity' => null,
            'facility_scale' => null,
            'facility_experience' => null,
            'facility_operational_state' => null,
        ]);

        if ($disasterKey === 'earthquake') {
            $this->setProfileFacility($cellIds, $factory->id, 1, 0);
        } elseif ($disasterKey === 'tsunami') {
            $targetIds = [];
            $waterIds = [];
            foreach ($cells as $cell) {
                if ((($cell->x + $cell->y) & 1) === 0) {
                    $targetIds[] = (int) $cell->id;
                } else {
                    $waterIds[] = (int) $cell->id;
                }
            }
            $this->setProfileFacility($targetIds, $farm->id, 1, 0);
            MapCell::query()->whereIn('id', $waterIds)->update([
                'terrain_definition_id' => $sea->id,
                'terrain_quantity' => null,
            ]);
        } elseif ($disasterKey === 'typhoon') {
            $targetIds = [];
            $protectionIds = [];
            foreach ($cells as $cell) {
                if ((($cell->x + $cell->y) & 1) === 0) {
                    $targetIds[] = (int) $cell->id;
                } else {
                    $protectionIds[] = (int) $cell->id;
                }
            }
            $this->setProfileFacility($targetIds, $farm->id, 1, 0);
            MapCell::query()->whereIn('id', $protectionIds)->update([
                'terrain_definition_id' => $forest->id,
                'terrain_quantity' => $forest->initial_quantity,
            ]);
        }

        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        foreach (['earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption'] as $key) {
            $settings['turn_processing']['disasters'][$key]['probability'] = [
                'numerator' => $key === $disasterKey ? 1 : 0,
                'denominator' => 1,
            ];
            $settings['turn_processing']['disasters'][$key]['center_padding'] = 0;
        }
        $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 0, 'denominator' => 1];
        $settings['turn_processing']['disasters']['earthquake']['damage_probability'] = [
            'numerator' => 1,
            'denominator' => 1,
        ];
        $settings['turn_processing']['disasters']['tsunami']['internal_denominator'] = 1;
        $settings['turn_processing']['disasters']['tsunami']['adjacent_water_offset'] = 0;
        $settings['turn_processing']['disasters']['typhoon']['internal_denominator'] = 1;
        $settings['turn_processing']['disasters']['typhoon']['base_damage_threshold'] = 7;
        $settings['turn_processing']['disasters']['meteor_shower']['continuation_probability'] = [
            'numerator' => 15,
            'denominator' => 16,
        ];
        $settings['turn_processing']['disasters']['land_subsidence']['enabled'] = $disasterKey === 'land_subsidence';
        $settings['turn_processing']['disasters']['land_subsidence']['base_safe_land_cells'] = 0;
        $settings['turn_processing']['disasters']['land_subsidence']['probability'] = [
            'numerator' => 1,
            'denominator' => 1,
        ];
        $settings['monster_system']['natural_spawn']['probability_per_land_cell'] = [
            'numerator' => 0,
            'denominator' => 10_000,
        ];
        $ruleset->settings = $settings;
        $ruleset->save();

        return [$world, $ruleset->fresh()];
    }

    private function forcedDisasterSeed(string $disasterKey): string
    {
        if ($disasterKey === 'land_subsidence') {
            return hash('sha256', 'turn-runtime-forced-land-subsidence');
        }
        $centerLabel = match ($disasterKey) {
            'earthquake' => TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER,
            'tsunami' => TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER,
            'typhoon' => TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER,
            'meteor_shower' => TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER,
            'huge_meteor' => TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER,
            'eruption' => TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER,
        };
        $padding = in_array($disasterKey, ['earthquake', 'tsunami', 'typhoon', 'meteor_shower'], true)
            ? 10
            : ($disasterKey === 'huge_meteor' ? 2 : 1);

        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "turn-runtime-forced-{$disasterKey}-{$candidate}");
            $random = new TurnRandomStreamFactory($seed);
            $fractionalGate = $random->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($disasterKey))
                ->integer(0, 224);
            if ($fractionalGate < 31) {
                continue;
            }
            $center = $random->stream($centerLabel);
            $x = $center->integer(0, 59);
            $y = $center->integer(0, 59);
            if ($x < $padding || $x > 59 - $padding || $y < $padding || $y > 59 - $padding) {
                continue;
            }
            if ($disasterKey === 'meteor_shower') {
                $effect = $random->stream(TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_EFFECT);
                $iterations = 0;
                do {
                    $effect->integer(0, 330);
                    $continueDraw = $effect->integer(0, 15);
                    $iterations++;
                } while ($continueDraw < 15 && $iterations < 64);
                if ($iterations < 24 || $iterations >= 64) {
                    continue;
                }
            }

            return $seed;
        }

        $this->fail("Unable to find deterministic performance seed for {$disasterKey}.");
    }

    /**
     * @return array{
     *     total_wall_ms: float,
     *     total_queries: int,
     *     phases: array<string, array<string, mixed>>
     * }
     */
    private function measureGlobalDisasters(World $world, RulesetVersion $ruleset, string $seed): array
    {
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $nationIds = Nation::query()->where('world_id', $world->id)->where('state', 'active')
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);
        $state->setSurfaceCellIds(MapCell::query()
            ->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $context = new TurnContext(
            $world,
            $run,
            $ruleset,
            $world->current_turn + 1,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
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

        $probe->start();
        $probe->beginPhase('global_disasters');
        $started = hrtime(true);
        $result = app(CompleteTurnEngine::class)->execute('global_disasters', $context);
        $probe->endPhase($result);

        return [
            'total_wall_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            'total_queries' => $probe->totalQueryCount(),
            'phases' => $probe->phases(),
        ];
    }

    /**
     * @return array{
     *     total_wall_ms: float,
     *     total_queries: int,
     *     phases: array<string, array{
     *         queries: int,
     *         query_time_ms: float,
     *         query_types: array<string, int>,
     *         defense_lookup_queries: int,
     *         coordinate_cell_lookup_queries: int,
     *         active_nation_lookup_queries: int,
     *         terrain_definition_lookup_queries: int,
     *         monster_occupancy_lookup_queries: int,
     *         repeated_queries: list<array{count: int, sql: string}>,
     *         hydrated_models: array<string, int>,
     *         peak_memory_bytes: int,
     *         wall_ms: float,
     *         metrics: array<string, mixed>
     *     }>
     * }
     */
    private function measureTurn(World $world, ?string $seed = null): array
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
            new TurnRuntimeFixedSeedGenerator($seed),
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
        $reportMode = getenv('REPORT_TURN_RUNTIME_PERFORMANCE');
        if (! in_array($reportMode, ['1', 'phase'], true)) {
            return;
        }
        if ($reportMode === 'phase') {
            $phase = str_starts_with($profile, 'forced-') ? 'global_disasters' : 'process_cells';
            $measurement['phases'] = [$phase => $measurement['phases'][$phase]];
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
        $defenseLookupQueries = 0;
        $coordinateCellLookupQueries = 0;
        $activeNationLookupQueries = 0;
        $terrainDefinitionLookupQueries = 0;
        $monsterOccupancyLookupQueries = 0;
        foreach ($queries as $query) {
            $sql = preg_replace('/\s+/', ' ', strtolower(trim($query->sql))) ?? $query->sql;
            $normalized[$sql] = ($normalized[$sql] ?? 0) + 1;
            $type = strtolower(strtok(ltrim($query->sql), " \t\r\n") ?: 'unknown');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $queryTime += $query->time;
            if ($type === 'select'
                && str_contains($sql, 'from "map_cells"')
                && str_contains($sql, '"facility_definitions"')
                && in_array('defense', $query->bindings, true)) {
                $defenseLookupQueries++;
            }
            if ($type === 'select'
                && str_contains($sql, 'from "map_cells"')
                && str_contains($sql, '"map_space_id" = ?')
                && str_contains($sql, '"x" = ?')
                && str_contains($sql, '"y" = ?')) {
                $coordinateCellLookupQueries++;
            }
            if ($type === 'select'
                && str_contains($sql, 'from "nations"')
                && str_contains($sql, '"state" = ?')
                && str_contains($sql, 'exists')) {
                $activeNationLookupQueries++;
            }
            if ($type === 'select'
                && str_contains($sql, 'from "terrain_definitions"')
                && str_contains($sql, '"key" = ?')) {
                $terrainDefinitionLookupQueries++;
            }
            if ($type === 'select'
                && str_contains($sql, 'from "monster_occupancies"')
                && str_contains($sql, '"map_cell_id" = ?')) {
                $monsterOccupancyLookupQueries++;
            }
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
            'defense_lookup_queries' => $defenseLookupQueries,
            'coordinate_cell_lookup_queries' => $coordinateCellLookupQueries,
            'active_nation_lookup_queries' => $activeNationLookupQueries,
            'terrain_definition_lookup_queries' => $terrainDefinitionLookupQueries,
            'monster_occupancy_lookup_queries' => $monsterOccupancyLookupQueries,
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
    public function __construct(private ?string $seed = null) {}

    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return $this->seed ?? str_repeat('0', 64);
    }
}
