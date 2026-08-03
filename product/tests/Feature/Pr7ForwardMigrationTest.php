<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Pr7ForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_world_moves_explicitly_without_data_loss_or_published_ruleset_mutation(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $pr6 = RulesetVersion::query()->where('key', 'roadmap-pr6-v1')->firstOrFail();
        $pr7 = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr7->id]);
        $this->useRulesetAsCurrent('roadmap-pr7-v1');
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'PR7移行国');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $mapSpace,
            commandKey: 'build_farm',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
            quantity: 99,
        )['item'];
        $item->update(['parameters' => ['future_inventory_item_id' => 12]]);
        $item->refresh();

        $pr6Snapshot = $pr6->settings;
        $pr7Snapshot = $pr7->settings;
        $before = [
            'nation_id' => $nation->id,
            'money' => $nation->money,
            'population' => MapCell::query()->where('owner_nation_id', $nation->id)->sum('population'),
            'cell_count' => MapCell::query()->count(),
            'resources' => DB::table('nation_resources')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->pluck('amount')->all(),
            'queue_item_id' => $item->id,
            'quantity' => $item->quantity,
            'parameters' => $item->parameters,
        ];
        $migration = require database_path('migrations/2026_07_29_000000_publish_roadmap_pr7_ruleset.php');

        $migration->down();
        $this->assertSame($pr6->id, $world->fresh()->ruleset_version_id);

        $migration->up();

        $this->assertSame($pr7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr6Snapshot, $pr6->fresh()->settings);
        $this->assertSame($pr7Snapshot, $pr7->fresh()->settings);
        $this->assertSame(9_999, $pr7->fresh()->settings['base_money_capacity']);
        $this->assertSame(999_900, $pr7->fresh()->settings['base_food_capacity_tons']);
        $this->assertSame($before['nation_id'], $nation->fresh()->id);
        $this->assertSame($before['money'], $nation->fresh()->money);
        $this->assertSame($before['population'], MapCell::query()->where('owner_nation_id', $nation->id)->sum('population'));
        $this->assertSame($before['cell_count'], MapCell::query()->count());
        $this->assertSame(
            $before['resources'],
            DB::table('nation_resources')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->pluck('amount')->all(),
        );
        $migratedItem = NationCommandQueueItem::query()->findOrFail($item->id);
        $this->assertSame($before['quantity'], $migratedItem->quantity);
        $this->assertSame($before['parameters'], $migratedItem->parameters);
        $this->assertSame(
            'build_farm',
            $migratedItem->definition()->value('key'),
        );
        $this->assertSame(
            $pr7->id,
            $migratedItem->definition()->value('ruleset_version_id'),
        );

        $migration->down();
        $rolledBackItem = NationCommandQueueItem::query()->findOrFail($item->id);
        $this->assertSame($pr6->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr6->id, $rolledBackItem->definition()->value('ruleset_version_id'));
        $this->assertSame(
            0,
            DB::table('nation_command_queue_items')
                ->join('nation_command_queues', 'nation_command_queues.id', '=', 'nation_command_queue_items.nation_command_queue_id')
                ->join('nations', 'nations.id', '=', 'nation_command_queues.nation_id')
                ->join('command_definitions', 'command_definitions.id', '=', 'nation_command_queue_items.command_definition_id')
                ->join('worlds', 'worlds.id', '=', 'nations.world_id')
                ->where('worlds.id', $world->id)
                ->whereColumn('command_definitions.ruleset_version_id', '!=', 'worlds.ruleset_version_id')
                ->count(),
        );

        $migration->up();
        $this->assertSame($pr7->id, $world->fresh()->ruleset_version_id);
    }
}
