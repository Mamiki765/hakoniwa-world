<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\OceanWorldGenerator;
use App\Models\MapSpace;
use App\Models\User;
use App\Services\AssetManifestResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_negative_chunk_coordinates_and_nation_endpoints(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $mapSpace = MapSpace::query()->firstOrFail();

        $this->actingAs($user)->getJson("/api/v1/map-spaces/{$mapSpace->id}/chunks/-1/-1")
            ->assertOk()->assertJsonPath('data.chunk_q', -1)->assertJsonCount(256, 'data.cells');

        $nation = $this->actingAs($user)->postJson('/api/v1/nations', ['world_id' => $world->id, 'name' => 'API国'])
            ->assertCreated()
            ->assertJsonMissingPath('data.food')
            ->assertJsonPath('data.resources.0.key', 'wheat')
            ->assertJsonPath('data.resources.0.amount', 100)
            ->json('data');
        $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->assertJsonPath('data.id', $nation['id']);
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
