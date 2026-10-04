<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\MapChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class SeaAreaWeatherApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_map_metadata_batches_last_processed_weather_without_exposing_private_state(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '天候表示', '表示島主');
        $space = $this->surfaceMapSpace($world);
        $world->update(['current_turn' => 2]);
        $space->update(['max_x' => 29]);
        $chunks = MapChunk::query()->where('map_space_id', $space->id)->orderBy('chunk_y')->orderBy('chunk_x')->get();
        foreach (['sunny', 'rain', 'huge_meteor', null] as $index => $key) {
            $chunks[$index]->update(['weather_key' => $key, 'weather_turn' => $key === null ? null : 2]);
        }
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $public = $this->getJson("/api/v1/public/worlds/{$world->id}/map-spaces")->assertOk();
        $this->assertCount(1, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "map_chunks"')));
        $areas = $public->json('data.0.sea_areas');
        $this->assertCount(4, $areas);
        $this->assertSame(['sunny', 'rain', 'huge_meteor', null], array_map(static fn (array $area): ?string => $area['weather']['key'] ?? null, $areas));
        $this->assertSame(2, $areas[2]['weather']['turn']);
        $this->assertSame('終末', $areas[2]['weather']['label']);
        $this->assertSame(29, $areas[1]['bounds']['max_x']);
        $this->assertNotEmpty($areas[0]['name']);
        $private = $this->actingAs($user)->getJson("/api/v1/worlds/{$world->id}/map-spaces")->assertOk();
        $this->assertSame($areas, $private->json('data.0.sea_areas'));
        $detail = $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk();
        $this->assertSame($areas, $detail->json('data.map_space.sea_areas'));
        foreach (['random_seed', 'draw', 'probability', 'facility_definition_id', 'buried_treasure'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($areas, JSON_THROW_ON_ERROR));
        }
        // Updating the current Turn alone cannot turn this record into a prediction.
        $world->update(['current_turn' => 3]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/map-spaces")->assertOk()->assertJsonPath('data.0.sea_areas.0.weather.turn', 2);
    }
}
