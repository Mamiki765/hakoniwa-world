<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\MapChunkService;
use App\Application\UserMonumentDesignService;
use App\Domain\Economy\NationEconomyCalculator;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\SeaAreaNameResolver;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonumentDefinition;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class ApiAndAssetTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_original_monument_replacement_updates_every_existing_tile_and_deletes_old_gif(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        Storage::fake('monument_images');
        $gif = base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', true);
        $this->assertIsString($gif);
        $designs = app(UserMonumentDesignService::class);
        $design = $designs->save($user, '旧記念碑', UploadedFile::fake()->createWithContent('old.gif', $gif));
        $oldPath = $design->image_path;
        $this->assertNotNull($oldPath);
        Storage::disk('monument_images')->assertExists($oldPath);

        $original = MonumentDefinition::query()->where('key', 'original')->firstOrFail();
        $cells = MapCell::query()->where('chunk_x', 0)->where('chunk_y', 0)->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $cells);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        $monument = FacilityDefinition::query()->where('key', 'monument')->firstOrFail();
        foreach ($cells as $cell) {
            $state = app(MapCellStateService::class);
            $state->transitionTerrain($cell, $plain);
            $state->setFacility($cell, $monument);
            $cell->monument_definition_id = $original->id;
            $cell->monument_design_id = $design->id;
            $cell->save();
        }
        $space = $this->surfaceMapSpace($world);
        $chunks = app(MapChunkService::class);
        $before = $chunks->present($space, 0, 0, null);

        $updated = $designs->save($user, '新記念碑', UploadedFile::fake()->createWithContent('new.gif', $gif));
        $this->assertNotSame($oldPath, $updated->image_path);
        Storage::disk('monument_images')->assertMissing($oldPath);
        Storage::disk('monument_images')->assertExists($updated->image_path);
        $after = $chunks->present($space, 0, 0, null);
        $this->assertNotSame($before['version'], $after['version']);
        foreach ($cells as $cell) {
            $presented = collect($after['cells'])->first(
                static fn (array $row): bool => $row['x'] === $cell->x && $row['y'] === $cell->y,
            );
            $this->assertSame('新記念碑', $presented['display_name']);
            $this->assertSame($designs->imageUrl($updated->image_path), $presented['asset']['url']);
        }
    }

    public function test_api_requires_auth_and_never_exposes_provider_user_id(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $user = app(AuthIdentityService::class)->authenticate('discord', new ExternalIdentityData('secret-external-id', 'Alice'));

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
        $response->assertJsonMissing(['provider_user_id' => 'secret-external-id']);
        $this->assertStringNotContainsString('secret-external-id', $response->getContent());
    }

    public function test_nation_creation_classifies_player_conflicts(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $base = [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => '分類試験島',
            'owner_name' => '分類島主',
            'comment' => '',
        ];

        $this->actingAs($owner)->postJson('/api/v1/nations', [...$base, 'name' => "改行\n島"])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($owner)->postJson('/api/v1/nations', $base)->assertCreated();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/nations', [
            ...$base,
            'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($other)->postJson('/api/v1/nations', [
            ...$base,
            'name' => '別要求島',
        ])->assertConflict()->assertJsonPath('code', 'nation_creation_request_conflict');
        $this->actingAs($owner)->postJson('/api/v1/nations', [
            ...$base,
            'request_key' => (string) Str::uuid(),
            'name' => '二重所属島',
        ])->assertConflict()->assertJsonPath('code', 'nation_creation_conflict');
    }

    public function test_shared_economy_calculator_does_not_query_persistent_state(): void
    {
        $world = $this->lightweightWorld();
        $rulesetSettings = $world->rulesetVersion()->firstOrFail()->settings;
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(NationEconomyCalculator::class)->calculate(
            $rulesetSettings,
            'active',
            10,
            5,
            [['cell_id' => 1, 'key' => 'factory', 'capacity' => 5]],
            1,
        );
        $calculatorQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $calculatorQueries, 'The shared economy calculator must not query persistent state.');
    }

    public function test_map_space_bounds_and_xy_chunk_coordinates(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $mapSpace = MapSpace::query()->firstOrFail();

        $this->actingAs($user)->getJson("/api/v1/worlds/{$world->id}/map-spaces")
            ->assertOk()
            ->assertJsonPath('data.0.bounds.min_x', 0)
            ->assertJsonPath('data.0.bounds.max_x', 31)
            ->assertJsonPath('data.0.bounds_revision', $mapSpace->boundsRevision());

        $this->actingAs($user)->getJson("/api/v1/map-spaces/{$mapSpace->id}/chunks/-1/-1")
            ->assertOk()->assertJsonPath('data.chunk_x', -1)->assertJsonPath('data.chunk_y', -1)
            ->assertJsonPath('data.state', 'empty')->assertJsonCount(0, 'data.cells');
        $this->actingAs($user)->getJson("/api/v1/map-spaces/{$mapSpace->id}/chunks/0/0")
            ->assertOk()->assertJsonPath('data.chunk_x', 0)->assertJsonPath('data.chunk_y', 0)
            ->assertJsonCount(256, 'data.cells');
    }

    public function test_nation_registration_returns_ruleset_capacities_and_replays_by_request_key(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $rulesetSettings = $world->rulesetVersion()->firstOrFail()->settings;
        // The HUD must use the active snapshot rather than a hard-coded 100-cell limit.
        $rulesetSettings['turn_processing']['disasters']['land_subsidence']['base_safe_land_cells'] = 123;
        $world->rulesetVersion()->firstOrFail()->update(['settings' => $rulesetSettings]);

        [$nation, $payload] = $this->registerApiNation($world, $user);
        $this->assertSame('API島主', $nation['owner_name']);
        $this->assertSame('公開プロフィール', $nation['comment']);
        $this->assertArrayNotHasKey('food', $nation);
        $this->assertSame(10_000, $nation['total_food_tons']);
        $this->assertSame(10_000, $nation['food_total_tons']);
        $this->assertSame(123, $nation['safe_land_cells']);
        $this->assertSame(10_098, $nation['money_capacity']);
        $this->assertSame(1_009_899, $nation['food_capacity_tons']);
        $resources = collect($nation['resources'])->keyBy('key');
        $this->assertSame(
            ['unit' => 'ton', 'amount' => 10_000, 'capacity' => 1_009_899, 'remaining_capacity' => 999_899, 'is_at_capacity' => false],
            collect($resources['wheat'])->only(['amount', 'unit', 'capacity', 'remaining_capacity', 'is_at_capacity'])->all(),
        );
        $this->assertSame(['unit', 9_999_000], [$resources['industrial_goods']['unit'], $resources['industrial_goods']['capacity']]);
        $this->assertSame(['ton', 9_999_000], [$resources['minerals']['unit'], $resources['minerals']['capacity']]);
        $this->assertSame(['ten_thousand_barrels', 0, 5_000], [$resources['oil']['unit'], $resources['oil']['amount'], $resources['oil']['capacity']]);

        $this->actingAs($user)->postJson('/api/v1/nations', [...$payload, 'name' => '再送時変更名'])
            ->assertCreated()->assertJsonPath('data.id', $nation['id']);
        $this->assertSame(1, Nation::query()->where('world_id', $world->id)->count());

        // A non-matching primary key proves chunk owners expose both the id and the nation number.
        $ownedCell = MapCell::query()->where('owner_nation_id', $nation['id'])->firstOrFail();
        $presentedOwnedCell = collect($this->actingAs($user)->getJson(
            "/api/v1/map-spaces/{$ownedCell->map_space_id}/chunks/{$ownedCell->chunk_x}/{$ownedCell->chunk_y}",
        )->assertOk()->json('data.cells'))->first(
            fn (array $cell): bool => $cell['x'] === $ownedCell->x && $cell['y'] === $ownedCell->y,
        );
        $this->assertNotSame($nation['id'], $nation['nation_number']);
        $this->assertSame($nation['id'], $presentedOwnedCell['owner_nation_id']);
        $this->assertSame($nation['nation_number'], $presentedOwnedCell['owner_nation_number']);
        $expectedSeaArea = app(SeaAreaNameResolver::class)->forCoordinate($ownedCell->x, $ownedCell->y);
        $this->assertSame($expectedSeaArea, $presentedOwnedCell['sea_area_name']);
        $this->assertSame($expectedSeaArea, collect($presentedOwnedCell['details'])->firstWhere('key', 'sea_area')['formatted']);
    }

    public function test_owner_resource_forecast_and_workforce_follow_facilities_and_dormancy(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        [$nation] = $this->registerApiNation($world, $user);
        $nationModel = Nation::query()->findOrFail($nation['id']);
        $scaleCells = $this->placeApiScaleFacilities($nationModel);
        MapCell::query()->where('owner_nation_id', $nationModel->id)->update(['population' => 0]);
        $nationModel->capital()->firstOrFail()->cell()->update(['population' => 25_000]);
        $secretaryId = (int) DB::table('secretaries')->where('user_id', $user->id)->value('id');
        foreach (['agricultural_policy' => 10, 'specialty_development' => 20, 'gold_vein_survey' => 30] as $skillKey => $level) {
            DB::table('secretary_skills')->where('secretary_id', $secretaryId)
                ->where('skill_key', $skillKey)->update(['level' => $level]);
        }
        $this->setApiResources($nationModel, ['wheat' => 100, 'fish' => 200, 'monster_meat' => 300, 'oil' => 123]);
        $oilCell = MapCell::query()->where('owner_nation_id', $nationModel->id)
            ->whereNull('facility_definition_id')->firstOrFail()->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->transitionTerrain(
            $oilCell,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $oilCell,
            FacilityDefinition::query()->where('key', 'seabed_oil_field')->firstOrFail(),
        );
        $oilCell->save();

        $shipCells = MapCell::query()->where('map_space_id', $oilCell->map_space_id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->limit(3)->get();
        $this->assertCount(3, $shipCells);
        foreach ([['fishing', 1], ['tourist', 2], ['exploration', 2]] as $index => [$type, $maxHp]) {
            Ship::query()->create([
                'world_id' => $world->id,
                'ruleset_version_id' => $world->ruleset_version_id,
                'nation_id' => $nationModel->id,
                'map_cell_id' => $shipCells[$index]->id,
                'ship_type_key' => $type,
                'current_hp' => $maxHp,
                'max_hp' => $maxHp,
                'heading' => null,
                'state' => Ship::STATE_ACTIVE,
                'version' => 1,
            ]);
        }

        $forecast = $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()
            ->assertJsonPath('data.farm_capacity_people', 10_000)
            ->assertJsonPath('data.factory_capacity_people', 30_000)
            ->assertJsonPath('data.mine_capacity_people', 5_000)
            ->assertJsonPath('data.resource_forecast.workforce.status', 'saturation')
            ->assertJsonPath('data.resource_forecast.workforce.percentage_tenths', 444);
        $rows = $this->forecastRows($forecast->json('data'));
        $this->assertSame([10_100, 5_000, 5_100, 900], [$rows['food']['production'], $rows['food']['consumption'], $rows['food']['delta'], $rows['food']['holding']]);
        $this->assertSame([13_114, 0], [$rows['industrial_goods']['production'], $rows['industrial_goods']['consumption']]);
        $this->assertSame(2_207, $rows['minerals']['production']);
        $this->assertSame([500, 4, 496, 123], [$rows['oil']['production'], $rows['oil']['consumption'], $rows['oil']['delta'], $rows['oil']['holding']]);
        $this->assertArrayHasKey('power', $rows);

        foreach ([1, 2] as $index) {
            $cell = $scaleCells[$index]->fresh(['facility']);
            app(MapCellStateService::class)->setFacility($cell, null);
            $cell->save();
        }
        $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()
            ->assertJsonPath('data.resource_forecast.workforce.status', 'unemployment')
            ->assertJsonPath('data.resource_forecast.workforce.percentage_tenths', 600);
        MapCell::query()->where('owner_nation_id', $nationModel->id)->update(['population' => 0]);
        $nationModel->capital()->firstOrFail()->cell()->update(['population' => 10_000]);
        $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()
            ->assertJsonPath('data.resource_forecast.workforce.status', 'saturation')
            ->assertJsonPath('data.resource_forecast.workforce.percentage_tenths', 0);
        $underseaCell = MapCell::query()->where('owner_nation_id', $nationModel->id)
            ->whereNull('facility_definition_id')->firstOrFail()->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->transitionTerrain(
            $underseaCell,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility(
            $underseaCell,
            FacilityDefinition::query()->where('key', 'undersea_city')->firstOrFail(),
        );
        $underseaCell->population = 3_000;
        $underseaCell->save();
        $this->setApiResources($nationModel, ['industrial_goods' => 500, 'minerals' => 2_000]);
        $rows = $this->forecastRows($this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data'));
        $this->assertSame([500, 2_000], [$rows['industrial_goods']['consumption'], $rows['minerals']['consumption']]);
        $nationModel->update([
            'state' => 'dormant',
            'state_reason' => 'manual',
            'state_started_turn' => $world->current_turn,
            'resume_at_turn' => $world->current_turn + 12,
        ]);
        $rows = $this->forecastRows($this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data'));
        $this->assertSame([0, 0, 0], [$rows['industrial_goods']['consumption'], $rows['minerals']['consumption'], $rows['oil']['consumption']]);
        $nationModel->update([
            'state' => 'active',
            'state_reason' => null,
            'state_started_turn' => null,
            'resume_at_turn' => null,
        ]);
        $this->setApiResources($nationModel, ['minerals' => 1_500]);
        $rows = $this->forecastRows($this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data'));
        $this->assertSame([0, 0], [$rows['industrial_goods']['consumption'], $rows['minerals']['consumption']]);
    }

    public function test_public_nation_projections_match_owner_totals_without_private_resources(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        [$nation] = $this->registerApiNation($world, $user);
        $nationModel = Nation::query()->findOrFail($nation['id']);
        $this->placeApiScaleFacilities($nationModel);
        $customFood = ResourceDefinition::query()->create([
            'key' => 'seaweed',
            'name' => '海藻',
            'category' => 'food',
            'unit' => 'ton',
            'unit_label' => 'トン',
            'nutrition_per_unit' => 9,
            'storable' => true,
            'tradable' => false,
            'sale_price_key' => null,
            'sort_order' => 35,
            'metadata' => [],
        ]);
        NationResource::query()->create([
            'nation_id' => $nation['id'],
            'resource_definition_id' => $customFood->id,
            'amount' => 250,
        ]);

        Nation::query()->whereKey($nation['id'])->update(['money' => 62_728]);
        $ownerStatus = $this->actingAs($user)->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.money', 62_728)
            ->assertJsonPath('data.total_food_tons', 10_250)
            ->assertJsonPath('data.food_total_tons', 10_250)
            ->json('data');
        $this->assertSame(250, collect($ownerStatus['food_resources'])->firstWhere('key', 'seaweed')['balance']);
        $this->assertSame(12_250, $this->forecastRows($ownerStatus)['food']['holding']);

        $other = User::factory()->create();
        $otherResponse = $this->actingAs($other)->getJson("/api/v1/nations/{$nation['id']}")->assertOk();
        $otherResponse->assertJsonPath('data.owner_name', 'API島主')
            ->assertJsonPath('data.food_total_tons', 10_250)
            ->assertJsonPath('data.farm_capacity_people', 10_000)
            ->assertJsonPath('data.factory_capacity_people', 30_000)
            ->assertJsonPath('data.mine_capacity_people', 5_000);
        $otherResponse->assertJsonMissingPath('data.total_food_tons')
            ->assertJsonMissingPath('data.money')
            ->assertJsonMissingPath('data.money_capacity')
            ->assertJsonMissingPath('data.food_capacity_tons')
            ->assertJsonMissingPath('data.resource_forecast')
            ->assertJsonMissingPath('data.food_resources')
            ->assertJsonMissingPath('data.resources');
        $publicRanking = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->json('data.0');
        $publicDetail = $this->getJson("/api/v1/public/nations/{$nation['id']}")
            ->assertOk()->json('data');
        foreach ([
            'total_population', 'territory_cell_count', 'owned_land_cells', 'food_total_tons',
            'farm_capacity_people', 'factory_capacity_people', 'mine_capacity_people',
            'recovery_remaining_turns', 'survival_turns', 'finance_only_turns', 'activity_status',
        ] as $field) {
            $this->assertSame($ownerStatus[$field], $publicRanking[$field]);
            $this->assertSame($ownerStatus[$field], $publicDetail[$field]);
        }
        foreach ([$otherResponse->getContent(), json_encode($publicRanking), json_encode($publicDetail)] as $publicBody) {
            $this->assertIsString($publicBody);
            foreach (['62728', 'seaweed', 'wheat', 'fish', 'monster_meat', 'industrial_goods', 'minerals', 'food_capacity_tons'] as $privateValue) {
                $this->assertStringNotContainsString($privateValue, $publicBody);
            }
        }
    }

    public function test_chunk_row_is_not_exposed_as_generated_when_current_bounds_intersection_is_incomplete(): void
    {
        $world = $this->lightweightWorld();
        $mapSpace = $this->surfaceMapSpace($world);
        MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('chunk_x', 0)
            ->where('chunk_y', 0)
            ->orderBy('id')
            ->firstOrFail()
            ->delete();

        $this->expectException(DomainException::class);
        app(MapChunkService::class)->present($mapSpace, 0, 0, null);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function registerApiNation(World $world, User $user): array
    {
        // Move the id sequence away from nation_number 1 so the two identifiers cannot be confused.
        DB::statement("SELECT setval(pg_get_serial_sequence('nations', 'id'), 18, false)");
        $payload = [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => 'API国',
            'owner_name' => 'API島主',
            'comment' => '公開プロフィール',
        ];
        $nation = $this->actingAs($user)->postJson('/api/v1/nations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.nation_number', 1)
            ->json('data');

        return [$nation, $payload];
    }

    /** @return Collection<int, MapCell> */
    private function placeApiScaleFacilities(Nation $nation): Collection
    {
        $scaleCells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->limit(3)->get();
        foreach (['farm', 'factory', 'mine'] as $index => $facilityKey) {
            $cell = $scaleCells[$index]->fresh(['terrain', 'facility']);
            app(MapCellStateService::class)->transitionTerrain(
                $cell,
                TerrainDefinition::query()->where('key', $facilityKey === 'mine' ? 'mountain' : 'plain')->firstOrFail(),
            );
            $facility = FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail();
            app(MapCellStateService::class)->setFacility($cell, $facility, $facility->initial_scale);
            $cell->save();
        }

        return $scaleCells;
    }

    /** @param array<string, int> $amounts */
    private function setApiResources(Nation $nation, array $amounts): void
    {
        foreach ($amounts as $resourceKey => $amount) {
            NationResource::query()->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
                ->update(['amount' => $amount]);
        }
    }

    /**
     * @param  array<string, mixed>  $nation
     * @return array<string, array<string, mixed>>
     */
    private function forecastRows(array $nation): array
    {
        return collect($nation['resource_forecast']['rows'])->keyBy('key')->all();
    }
}
