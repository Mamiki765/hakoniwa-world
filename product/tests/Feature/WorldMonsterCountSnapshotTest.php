<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\WorldMonsterCountSnapshot;
use App\Domain\Map\MapCellStateService;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class WorldMonsterCountSnapshotTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    public function test_counts_actor_categories_and_hosts_once_and_public_reads_use_the_saved_snapshot(): void
    {
        $world = $this->lightweightWorld();
        $first = app(NationCreationService::class)->create(User::factory()->create(), $world, '集計島', '集計島主');
        $empty = app(NationCreationService::class)->create(User::factory()->create(), $world, '空の島', '空の島主');
        $summaryUrl = "/api/v1/public/worlds/{$world->id}/summary";
        $rankingsUrl = "/api/v1/public/worlds/{$world->id}/rankings";
        $this->getJson($summaryUrl)->assertOk()->assertJsonPath('data.monster_counts', null);
        $this->assertNull(collect($this->getJson($rankingsUrl)->assertOk()->json('data'))->keyBy('id')[$first->id]['alive_monster_count']);

        $owned = MapCell::query()->where('owner_nation_id', $first->id)
            ->whereNull('facility_definition_id')->orderBy('id')->limit(2)->get();
        $neutral = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')->orderBy('id')->limit(3)->get();
        $this->monster($world, $owned[0], 'inora', 'wasteland');
        $this->monster($world, $owned[1], 'whale', 'wasteland');
        $this->monster($world, $neutral[0], 'inora', 'wasteland');
        $this->monster($world, $neutral[1], 'aoi_inora', 'sea');
        $removed = $this->monster($world, $neutral[2], 'inora', 'wasteland');
        $removed->occupancy()->delete();
        $removed->update(['state' => 'removed', 'removal_reason' => 'terrain_overwrite', 'removed_at' => now()]);

        app(WorldMonsterCountSnapshot::class)->record($world, (int) $world->current_turn);
        $expected = ['counted_turn' => (int) $world->current_turn, 'total_alive_count' => 4, 'land_alive_count' => 3, 'sea_alive_count' => 1];
        $this->monster($world, $neutral[2], 'inora', 'wasteland');
        // A later supported registration has no entry in the earlier snapshot.
        $later = app(NationCreationService::class)->create(User::factory()->create(), $world, '後からの島', '後からの島主');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summary = $this->getJson($summaryUrl)->assertOk()->assertJsonPath('data.monster_counts', $expected);
        $rankings = collect($this->getJson($rankingsUrl)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rankings[$first->id]['alive_monster_count']);
        $this->assertSame(0, $rankings[$empty->id]['alive_monster_count']);
        $this->assertNull($rankings[$later->id]['alive_monster_count']);
        $this->assertSame((int) $world->current_turn, $rankings[$later->id]['monster_counted_turn']);
        $this->assertArrayNotHasKey('nation_counts', $summary->json('data.monster_counts'));
        $sql = implode('\n', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertStringNotContainsString('monster_instances', $sql);
        $this->assertStringNotContainsString('monster_occupancies', $sql);
    }

    private function monster(World $world, MapCell $cell, string $key, string $terrainKey): MonsterInstance
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        $cell->population = 0;
        $cell->save();
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)->where('key', $key)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id, 'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp, 'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive', 'spawned_target_turn' => $world->current_turn, 'version' => 1,
        ]);
        MonsterOccupancy::query()->create(['monster_instance_id' => $monster->id, 'map_cell_id' => $cell->id]);

        return $monster;
    }
}
