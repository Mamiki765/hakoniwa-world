<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\MapChunkService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\SeaAreaNameResolver;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Services\AssetManifestResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class ApiAndAssetTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_api_requires_auth_and_never_exposes_provider_user_id(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $user = app(AuthIdentityService::class)->authenticate('discord', new ExternalIdentityData('secret-external-id', 'Alice'));

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
        $response->assertJsonMissing(['provider_user_id' => 'secret-external-id']);
        $this->assertStringNotContainsString('secret-external-id', $response->getContent());
    }

    public function test_xy_chunk_coordinates_and_nation_endpoints(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $mapSpace = MapSpace::query()->firstOrFail();
        DB::statement("SELECT setval(pg_get_serial_sequence('nations', 'id'), 18, false)");

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

        $registrationKey = (string) Str::uuid();
        $registrationPayload = [
            'request_key' => $registrationKey,
            'world_id' => $world->id,
            'name' => 'API国',
            'owner_name' => 'API島主',
            'comment' => '公開プロフィール',
        ];
        $nation = $this->actingAs($user)->postJson('/api/v1/nations', $registrationPayload)
            ->assertCreated()
            ->assertJsonPath('data.id', 18)
            ->assertJsonPath('data.nation_number', 1)
            ->assertJsonPath('data.owner_name', 'API島主')
            ->assertJsonPath('data.comment', '公開プロフィール')
            ->assertJsonMissingPath('data.food')
            ->assertJsonPath('data.resources.0.key', 'wheat')
            ->assertJsonPath('data.resources.0.amount', 10_000)
            ->assertJsonPath('data.resources.0.unit', 'ton')
            ->assertJsonPath('data.resources.0.unit_label', 'トン')
            ->assertJsonPath('data.total_food_tons', 10_000)
            ->assertJsonPath('data.food_total_tons', 10_000)
            ->assertJsonPath('data.money_capacity', 9_999)
            ->assertJsonPath('data.food_capacity_tons', 999_900)
            ->assertJsonPath('data.food_resources.0.balance', 10_000)
            ->assertJsonPath('data.resources.0.capacity', 999_900)
            ->assertJsonPath('data.resources.0.remaining_capacity', 989_900)
            ->assertJsonPath('data.resources.0.is_at_capacity', false)
            ->assertJsonPath('data.resources.3.unit', 'unit')
            ->assertJsonPath('data.resources.3.unit_label', 'ユニット')
            ->assertJsonPath('data.resources.3.capacity', 9_999_000)
            ->assertJsonPath('data.resources.4.unit', 'ton')
            ->assertJsonPath('data.resources.4.unit_label', 'トン')
            ->assertJsonPath('data.resources.4.capacity', 9_999_000)
            ->json('data');
        $this->actingAs($user)->postJson('/api/v1/nations', [
            ...$registrationPayload,
            'name' => '再送時変更名',
        ])->assertCreated()->assertJsonPath('data.id', $nation['id']);
        $this->assertSame(1, Nation::query()->where('world_id', $world->id)->count());
        $scaleCells = MapCell::query()->where('owner_nation_id', $nation['id'])
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
        $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()
            ->assertJsonPath('data.id', $nation['id'])
            ->assertJsonPath('data.farm_capacity_people', 10_000)
            ->assertJsonPath('data.factory_capacity_people', 30_000)
            ->assertJsonPath('data.mine_capacity_people', 5_000);
        $ownedCell = MapCell::query()->where('owner_nation_id', $nation['id'])->firstOrFail();
        $ownedChunk = $this->actingAs($user)->getJson(
            "/api/v1/map-spaces/{$mapSpace->id}/chunks/{$ownedCell->chunk_x}/{$ownedCell->chunk_y}",
        )->assertOk();
        $presentedOwnedCell = collect($ownedChunk->json('data.cells'))->first(
            fn (array $cell): bool => $cell['x'] === $ownedCell->x && $cell['y'] === $ownedCell->y,
        );
        $this->assertSame(18, $presentedOwnedCell['owner_nation_id']);
        $this->assertSame(1, $presentedOwnedCell['owner_nation_number']);
        $expectedSeaArea = app(SeaAreaNameResolver::class)->forCoordinate($ownedCell->x, $ownedCell->y);
        $this->assertSame($expectedSeaArea, $presentedOwnedCell['sea_area_name']);
        $this->assertSame($expectedSeaArea, collect($presentedOwnedCell['details'])->firstWhere('key', 'sea_area')['formatted']);
        $this->assertSame(18, $ownedCell->owner_nation_id);

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
        $ownerStatus = $this->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.money', 62_728)
            ->assertJsonPath('data.money_display', '62,728億円')
            ->assertJsonPath('data.total_food_tons', 10_250)
            ->assertJsonPath('data.food_total_tons', 10_250)
            ->assertJsonPath('data.food_resources.3.key', 'seaweed')
            ->assertJsonPath('data.food_resources.3.balance', 250)
            ->json('data');

        $other = User::factory()->create();
        $otherResponse = $this->actingAs($other)->getJson("/api/v1/nations/{$nation['id']}")->assertOk();
        $otherResponse->assertJsonPath('data.owner_name', 'API島主')
            ->assertJsonPath('data.comment', '公開プロフィール')
            ->assertJsonPath('data.money_display', '約62,000億円')
            ->assertJsonPath('data.food_total_tons', 10_250)
            ->assertJsonPath('data.farm_capacity_people', 10_000)
            ->assertJsonPath('data.factory_capacity_people', 30_000)
            ->assertJsonPath('data.mine_capacity_people', 5_000);
        $otherResponse->assertJsonMissingPath('data.total_food_tons')
            ->assertJsonMissingPath('data.money')
            ->assertJsonMissingPath('data.money_capacity')
            ->assertJsonMissingPath('data.food_capacity_tons')
            ->assertJsonMissingPath('data.food_resources')
            ->assertJsonMissingPath('data.resources');
        $publicRanking = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->json('data.0');
        $publicDetail = $this->getJson("/api/v1/public/nations/{$nation['id']}")
            ->assertOk()->json('data');
        foreach ([
            'total_population', 'territory_cell_count', 'owned_land_cells', 'food_total_tons',
            'farm_capacity_people', 'factory_capacity_people', 'mine_capacity_people',
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

    public function test_assets_fall_back_without_external_gifs_including_capital(): void
    {
        config(['hakoniwa.assets.path' => storage_path('missing-original-assets')]);
        $resolver = app(AssetManifestResolver::class);

        $this->assertFalse($resolver->resolve('hakoniwa_original.sea', '海')['available']);
        $this->assertNull($resolver->resolve('tile.capital', '首都')['url']);
        $this->assertFalse($resolver->resolve('unknown.asset', '?')['available']);
    }
}
