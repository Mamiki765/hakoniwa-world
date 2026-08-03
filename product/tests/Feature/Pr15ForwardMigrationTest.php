<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Pr15ForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_world_migration_preserves_gameplay_data_and_maps_queued_commands_by_key(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'PR15移行国');
        $pr14 = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $pr15 = RulesetVersion::query()->where('key', 'roadmap-pr15-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr14->id, 'current_turn' => 7]);
        $this->useRulesetAsCurrent('roadmap-pr14-v1');
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
        $cells = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
            ->get([
                'id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id',
                'population', 'terrain_quantity', 'facility_scale', 'version',
            ])->map->toArray()->all();
        $nationSnapshot = $nation->fresh()->only(['id', 'money', 'current_turn', 'state']);
        $capitalSnapshot = $nation->capital()->firstOrFail()->only(['id', 'map_cell_id', 'x', 'y']);
        $migration = require database_path('migrations/2026_08_02_010000_publish_roadmap_pr15_ruleset.php');

        $migration->up();

        $this->assertSame($pr15->id, $world->fresh()->ruleset_version_id);
        $this->assertSame(7, $world->fresh()->current_turn);
        $this->assertSame($nationSnapshot, $nation->fresh()->only(['id', 'money', 'current_turn', 'state']));
        $this->assertSame($capitalSnapshot, $nation->capital()->firstOrFail()->only(['id', 'map_cell_id', 'x', 'y']));
        $this->assertSame(
            $cells,
            MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
                ->get([
                    'id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id',
                    'population', 'terrain_quantity', 'facility_scale', 'version',
                ])->map->toArray()->all(),
        );
        $migrated = NationCommandQueueItem::query()->findOrFail($item->id);
        $this->assertSame('build_farm', $migrated->definition()->value('key'));
        $this->assertSame($pr15->id, $migrated->definition()->value('ruleset_version_id'));
        $this->assertSame(30, $migrated->quantity);
        $this->assertSame('queued', $migrated->status);

        $migration->up();
        $this->assertSame($pr15->id, $world->fresh()->ruleset_version_id);
    }

    public function test_shared_world_migration_fails_closed_with_a_recorded_next_turn_run(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $pr14 = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr14->id]);
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $pr14->id,
            'random_seed' => str_repeat('e', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_02_010000_publish_roadmap_pr15_ruleset.php');

        try {
            $migration->up();
            $this->fail('Migration changed the ruleset while a next-turn run required PR14.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("turn run {$run->id}", $exception->getMessage());
            $this->assertStringContainsString('roadmap-pr14-v1', $exception->getMessage());
        }

        $this->assertSame($pr14->id, $world->fresh()->ruleset_version_id);
    }

    public function test_shared_world_ruleset_migration_is_forward_only(): void
    {
        $migration = require database_path('migrations/2026_08_02_010000_publish_roadmap_pr15_ruleset.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }
}
