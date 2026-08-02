<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Pr14ForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_world_migration_preserves_cells_and_maps_every_queued_command_by_key(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'PR14移行国');
        $pr11 = RulesetVersion::query()->where('key', 'roadmap-pr11-v1')->firstOrFail();
        $pr14 = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr11->id]);
        $space = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'build_farm',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
            quantity: 30,
        )['item'];
        $unrelated = World::query()->create([
            'key' => 'pr14-unrelated-world',
            'name' => 'PR14無関係世界',
            'ruleset_version_id' => $pr11->id,
            'current_turn' => 8,
        ]);
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
            ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population'])
            ->map->toArray()->all();
        $migration = require database_path('migrations/2026_08_02_000000_publish_roadmap_pr14_ruleset.php');

        $migration->up();

        $this->assertSame($pr14->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr11->id, $unrelated->fresh()->ruleset_version_id);
        $this->assertSame(8, $unrelated->fresh()->current_turn);
        $this->assertSame(
            $cells,
            MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
                ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population'])
                ->map->toArray()->all(),
        );
        $migratedItem = NationCommandQueueItem::query()->findOrFail($item->id);
        $this->assertSame(30, $migratedItem->quantity);
        $this->assertSame('queued', $migratedItem->status);
        $this->assertSame('build_farm', $migratedItem->definition()->value('key'));
        $this->assertSame($pr14->id, $migratedItem->definition()->value('ruleset_version_id'));
        $this->assertSame(
            ['sea'],
            FacilityDefinition::query()->where('key', 'seabed_oil_field')->firstOrFail()->buildable_terrain_keys,
        );

        $migration->up();
        $this->assertSame($pr14->id, $world->fresh()->ruleset_version_id);
    }

    public function test_shared_world_ruleset_migration_is_forward_only(): void
    {
        $migration = require database_path('migrations/2026_08_02_000000_publish_roadmap_pr14_ruleset.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }
}
