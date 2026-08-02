<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\User;
use App\Services\AssetManifestResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiAndAssetTest extends TestCase
{
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
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $mapSpace = MapSpace::query()->firstOrFail();
        DB::statement("SELECT setval(pg_get_serial_sequence('nations', 'id'), 18, false)");

        $this->actingAs($user)->getJson("/api/v1/map-spaces/{$mapSpace->id}/chunks/-1/-1")
            ->assertOk()->assertJsonPath('data.chunk_x', -1)->assertJsonPath('data.chunk_y', -1)
            ->assertJsonPath('data.state', 'empty')->assertJsonCount(0, 'data.cells');
        $this->actingAs($user)->getJson("/api/v1/map-spaces/{$mapSpace->id}/chunks/0/0")
            ->assertOk()->assertJsonPath('data.chunk_x', 0)->assertJsonPath('data.chunk_y', 0)
            ->assertJsonCount(256, 'data.cells');

        $nation = $this->actingAs($user)->postJson('/api/v1/nations', ['world_id' => $world->id, 'name' => 'API国'])
            ->assertCreated()
            ->assertJsonPath('data.id', 18)
            ->assertJsonPath('data.nation_number', 1)
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
            ->json('data');
        $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->assertJsonPath('data.id', $nation['id']);
        $ownedCell = MapCell::query()->where('owner_nation_id', $nation['id'])->firstOrFail();
        $ownedChunk = $this->actingAs($user)->getJson(
            "/api/v1/map-spaces/{$mapSpace->id}/chunks/{$ownedCell->chunk_x}/{$ownedCell->chunk_y}",
        )->assertOk();
        $presentedOwnedCell = collect($ownedChunk->json('data.cells'))->first(
            fn (array $cell): bool => $cell['x'] === $ownedCell->x && $cell['y'] === $ownedCell->y,
        );
        $this->assertSame(18, $presentedOwnedCell['owner_nation_id']);
        $this->assertSame(1, $presentedOwnedCell['owner_nation_number']);
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

        $this->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.total_food_tons', 10_250)
            ->assertJsonPath('data.food_resources.3.key', 'seaweed')
            ->assertJsonPath('data.food_resources.3.balance', 250);

        $other = User::factory()->create();
        $otherResponse = $this->actingAs($other)->getJson("/api/v1/nations/{$nation['id']}")->assertOk();
        $otherResponse->assertJsonMissingPath('data.total_food_tons')
            ->assertJsonMissingPath('data.money')
            ->assertJsonMissingPath('data.money_capacity')
            ->assertJsonMissingPath('data.food_capacity_tons')
            ->assertJsonMissingPath('data.food_resources')
            ->assertJsonMissingPath('data.resources');
    }

    public function test_assets_fall_back_without_original_gifs_and_capital_is_placeholder(): void
    {
        config(['hakoniwa.assets.path' => storage_path('missing-original-assets')]);
        $resolver = app(AssetManifestResolver::class);

        $this->assertFalse($resolver->resolve('hakoniwa_original.sea', '海')['available']);
        $this->assertNull($resolver->resolve('hakoniwa_new.capital', '首都')['url']);
        $this->assertFalse($resolver->resolve('unknown.asset', '?')['available']);
    }
}
