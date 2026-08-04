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
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Pr11ForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_shared_world_migration_preserves_gameplay_and_identity_data(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'PR11移行国', '試験島主');
        $pr7 = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->firstOrFail();
        $pr11 = RulesetVersion::query()->where('key', 'roadmap-pr11-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr7->id]);
        $this->useRulesetAsCurrent('roadmap-pr7-v1');
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
            quantity: 30,
        )['item'];

        $unrelated = World::query()->create([
            'key' => 'unrelated-world',
            'name' => '無関係世界',
            'ruleset_version_id' => $pr7->id,
            'current_turn' => 8,
        ]);
        $before = [
            'user_id' => $user->id,
            'nation_id' => $nation->id,
            'money' => $nation->money,
            'capital_cell_id' => $nation->capital()->value('map_cell_id'),
            'cell_count' => MapCell::query()->where('map_space_id', $mapSpace->id)->count(),
            'cells' => MapCell::query()->where('map_space_id', $mapSpace->id)->orderBy('id')
                ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population'])
                ->map->toArray()->all(),
            'resources' => DB::table('nation_resources')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->get()->map(fn ($row) => (array) $row)->all(),
            'sale_policies' => DB::table('nation_resource_sale_policies')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->get()->map(fn ($row) => (array) $row)->all(),
            'audit_count' => DB::table('audit_events')->count(),
            'queue_item_id' => $item->id,
            'queue_quantity' => $item->quantity,
        ];
        $migration = require database_path('migrations/2026_07_30_000000_publish_roadmap_pr11_ruleset.php');

        $this->assertSame($pr7->id, $world->fresh()->ruleset_version_id);
        $migration->up();

        $this->assertSame($pr11->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr7->id, $unrelated->fresh()->ruleset_version_id);
        $this->assertSame(8, $unrelated->current_turn);
        $this->assertSame($before['user_id'], $user->fresh()->id);
        $this->assertSame($before['nation_id'], $nation->fresh()->id);
        $this->assertSame($before['money'], $nation->fresh()->money);
        $this->assertSame($before['capital_cell_id'], $nation->capital()->value('map_cell_id'));
        $this->assertSame($before['cell_count'], MapCell::query()->where('map_space_id', $mapSpace->id)->count());
        $this->assertSame(
            $before['cells'],
            MapCell::query()->where('map_space_id', $mapSpace->id)->orderBy('id')
                ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population'])
                ->map->toArray()->all(),
        );
        $this->assertSame(
            $before['resources'],
            DB::table('nation_resources')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->get()->map(fn ($row) => (array) $row)->all(),
        );
        $this->assertSame(
            $before['sale_policies'],
            DB::table('nation_resource_sale_policies')->where('nation_id', $nation->id)
                ->orderBy('resource_definition_id')->get()->map(fn ($row) => (array) $row)->all(),
        );
        $this->assertSame($before['audit_count'], DB::table('audit_events')->count());
        $migratedItem = NationCommandQueueItem::query()->findOrFail($before['queue_item_id']);
        $this->assertSame($before['queue_quantity'], $migratedItem->quantity);
        $this->assertSame('build_farm', $migratedItem->definition()->value('key'));
        $this->assertSame($pr11->id, $migratedItem->definition()->value('ruleset_version_id'));

        $migration->up();
        $this->assertSame($pr11->id, $world->fresh()->ruleset_version_id);
    }

    public function test_shared_world_ruleset_migration_is_forward_only(): void
    {
        $migration = require database_path('migrations/2026_07_30_000000_publish_roadmap_pr11_ruleset.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }

    public function test_shared_world_migration_refuses_legacy_wheat_sell_all_with_affected_ids(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'Legacy wheat seller', '試験島主');
        $pr7 = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $pr7->id]);
        $policy = DB::table('nation_resource_sale_policies')
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resource_sale_policies.resource_definition_id')
            ->where('nation_resource_sale_policies.nation_id', $nation->id)
            ->where('resource_definitions.key', 'wheat')
            ->select('nation_resource_sale_policies.id')
            ->first();
        $this->assertNotNull($policy);
        DB::table('nation_resource_sale_policies')->where('id', $policy->id)->update(['policy' => 'sell_all']);
        $migration = require database_path('migrations/2026_07_30_000000_publish_roadmap_pr11_ruleset.php');

        try {
            $migration->up();
            $this->fail('The migration accepted a PR11-incompatible wheat sell_all policy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $policy->id, $exception->getMessage());
            $this->assertStringContainsString("nation {$nation->id}", $exception->getMessage());
            $this->assertStringContainsString('policy sell_all', $exception->getMessage());
        }

        $this->assertSame($pr7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame('sell_all', DB::table('nation_resource_sale_policies')->where('id', $policy->id)->value('policy'));
    }
}
