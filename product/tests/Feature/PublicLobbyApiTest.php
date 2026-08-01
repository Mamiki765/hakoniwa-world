<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLobbyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_world_summary_rankings_and_safe_public_events(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $firstUser = User::factory()->create();
        $first = app(NationCreationService::class)->create($firstUser, $world, '第一国');
        $second = app(NationCreationService::class)->create(User::factory()->create(), $world, '第二国');

        MapCell::query()->whereIn('owner_nation_id', [$first->id, $second->id])->update(['population' => 0]);
        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        MapCell::query()->where('owner_nation_id', $second->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        $neutral = MapCell::query()->whereNull('owner_nation_id')->firstOrFail();
        $neutral->update(['owner_nation_id' => $second->id]);
        $first->update(['money' => 62728]);
        $second->update(['money' => 700]);

        $summary = $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.current_turn', 1)
            ->assertJsonPath('data.nation_count', 2)
            ->assertJsonPath('data.total_population', 2000);
        $this->assertStringContainsString('public', (string) $summary->headers->get('Cache-Control'));

        $ranking = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.money_display', '約500億円')
            ->assertJsonPath('data.0.money_bucket', '500')
            ->assertJsonPath('data.1.money_display', '約62,000億円')
            ->assertJsonPath('data.1.money_bucket', '62000');
        $rankingBody = $ranking->getContent();
        $this->assertStringNotContainsString('"money":', $rankingBody);
        $this->assertStringNotContainsString('62728', $rankingBody);

        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1500]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id);

        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        $neutral->update(['owner_nation_id' => null]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        $events = $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'nation_created')
            ->assertJsonStructure(['data' => [['id', 'type', 'message', 'metadata', 'occurred_at']]]);
        $eventsBody = $events->getContent();
        $this->assertStringNotContainsString('"x"', $eventsBody);
        $this->assertStringNotContainsString('"y"', $eventsBody);
        $this->assertStringNotContainsString('stack', $eventsBody);
        $this->assertStringNotContainsString('exception', $eventsBody);

        $private = $this->actingAs($firstUser)->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.money', 62728)
            ->assertJsonPath('data.money_display', '62,728億円')
            ->assertHeader('Vary', 'Cookie');
        $this->assertStringContainsString('no-store', (string) $private->headers->get('Cache-Control'));
    }

    public function test_public_events_have_an_empty_boundary_when_nothing_is_publishable(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();

        $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_guest_nation_preview_uses_viewer_safe_cells_and_never_leaks_exact_money(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '秘匿国');
        $nation->update(['money' => 62728]);
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $base = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->where('key', 'missile_base'))->firstOrFail();
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();

        $nationResponse = $this->getJson("/api/v1/public/nations/{$nation->id}")
            ->assertOk()
            ->assertJsonPath('data.capital.x', $nation->capital()->value('x'))
            ->assertJsonPath('data.map_space.id', $mapSpace->id)
            ->assertJsonPath('data.map_space.bounds.max_x', $mapSpace->max_x)
            ->assertJsonPath('data.money_display', '約62,000億円');
        $this->assertStringNotContainsString('62728', $nationResponse->getContent());
        $this->assertStringNotContainsString('total_food_tons', $nationResponse->getContent());
        $this->assertStringNotContainsString('food_resources', $nationResponse->getContent());
        $this->assertStringNotContainsString('wheat', $nationResponse->getContent());

        $url = "/api/v1/public/nations/{$nation->id}/map-spaces/{$mapSpace->id}/chunks/{$base->chunk_x}/{$base->chunk_y}";
        $response = $this->getJson($url)->assertOk();
        $publicBase = $this->cell($response->json('data.cells'), $base);
        $publicForest = $this->cell($response->json('data.cells'), $forest);
        $this->assertSame('forest', $publicBase['terrain']);
        $this->assertNull($publicBase['facility']);
        $this->assertSame([], $publicBase['details']);
        $this->assertSame([], $publicForest['details']);

        foreach (['x', 'y', 'aria_label'] as $key) {
            unset($publicBase[$key], $publicForest[$key]);
        }
        $this->assertSame($publicForest, $publicBase);
        $encoded = json_encode($publicBase, JSON_THROW_ON_ERROR);
        foreach (['missile', 'experience', 'level', 'operational', 'quantity', 'audit'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Vary'));
    }

    /** @param array<int, array<string, mixed>> $cells @return array<string, mixed> */
    private function cell(array $cells, MapCell $expected): array
    {
        $cell = collect($cells)->first(
            fn (array $cell): bool => $cell['x'] === $expected->x && $cell['y'] === $expected->y,
        );
        $this->assertIsArray($cell);

        return $cell;
    }
}
