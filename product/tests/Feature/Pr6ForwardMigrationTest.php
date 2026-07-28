<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\CommandQueueService;
use App\Application\ExternalIdentityData;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Pr6ForwardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr5_shaped_database_is_forward_migrated_without_rewriting_source_ruleset_or_losing_game_data(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('forward-user', 'Forward User'),
        );
        $nation = app(NationCreationService::class)->create($user, $world, '前方移行国');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $requestKey = (string) Str::uuid();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $mapSpace,
            commandKey: 'build_farm',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: $requestKey,
            expectedVersion: 1,
            quantity: 49,
        )['item'];
        $item->update(['parameters' => ['future_key' => 7]]);
        DB::table('nation_resources')
            ->where('nation_id', $nation->id)
            ->whereIn('resource_definition_id', DB::table('resource_definitions')->where('key', 'fish')->select('id'))
            ->update(['amount' => 300]);
        DB::table('nation_resources')
            ->where('nation_id', $nation->id)
            ->whereIn('resource_definition_id', DB::table('resource_definitions')->where('key', 'monster_meat')->select('id'))
            ->update(['amount' => 1200]);
        DB::table('nation_resources')
            ->where('nation_id', $nation->id)
            ->whereIn('resource_definition_id', DB::table('resource_definitions')->where('key', 'industrial_goods')->select('id'))
            ->update(['amount' => 7]);

        $foodMigration = require database_path('migrations/2026_07_28_010000_normalize_food_resources_to_tons.php');
        $quantityMigration = require database_path('migrations/2026_07_28_000000_add_universal_quantity_to_command_queue_items.php');
        $rulesetMigration = require database_path('migrations/2026_07_27_010000_publish_roadmap_pr6_ruleset.php');
        $foodMigration->down();
        $quantityMigration->down();
        $rulesetMigration->down();

        $sourceId = DB::table('ruleset_versions')->where('key', 'roadmap-pr2-v1')->value('id');
        $this->assertSame(
            ['future_key' => 7, 'quantity' => 49],
            NationCommandQueueItem::query()->findOrFail($item->id)->parameters,
        );
        $legacyBalances = DB::table('nation_resources')
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->where('nation_resources.nation_id', $nation->id)
            ->pluck('nation_resources.amount', 'resource_definitions.key');
        $this->assertSame(100, $legacyBalances['wheat']);
        $this->assertSame(3, $legacyBalances['fish']);
        $this->assertSame(12, $legacyBalances['monster_meat']);
        $this->assertSame(7, $legacyBalances['industrial_goods']);

        $sourceSettings = json_decode((string) DB::table('ruleset_versions')->where('id', $sourceId)
            ->value('settings'), true, 512, JSON_THROW_ON_ERROR);
        $sourceSettings['migration_sentinel'] = 'do-not-overwrite';
        DB::table('ruleset_versions')->where('id', $sourceId)->update([
            'settings' => json_encode($sourceSettings, JSON_THROW_ON_ERROR),
        ]);

        $before = [
            'user' => $user->id,
            'identity' => DB::table('auth_identities')->where('user_id', $user->id)->value('id'),
            'world' => $world->id,
            'nation' => $nation->id,
            'membership' => DB::table('nation_memberships')->where('nation_id', $nation->id)->value('id'),
            'queue' => $item->nation_command_queue_id,
            'item' => $item->id,
            'position' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('queue_position'),
            'status' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('status'),
            'request_key' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('request_key'),
            'queued_at' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('queued_at'),
            'created_at' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('created_at'),
            'updated_at' => DB::table('nation_command_queue_items')->where('id', $item->id)->value('updated_at'),
            'map_count' => DB::table('map_cells')->count(),
            'money' => DB::table('nations')->where('id', $nation->id)->value('money'),
            'population' => DB::table('map_cells')->where('owner_nation_id', $nation->id)->sum('population'),
        ];

        $rulesetMigration->up();
        $quantityMigration->up();
        $foodMigration->up();

        $targetRulesetId = DB::table('ruleset_versions')->where('key', 'roadmap-pr6-v1')->value('id');
        $this->assertSame($targetRulesetId, DB::table('worlds')->where('id', $world->id)->value('ruleset_version_id'));
        $this->assertSame('do-not-overwrite', json_decode((string) DB::table('ruleset_versions')
            ->where('id', $sourceId)->value('settings'), true, 512, JSON_THROW_ON_ERROR)['migration_sentinel']);
        $this->assertSame($before['user'], DB::table('users')->where('id', $user->id)->value('id'));
        $this->assertSame($before['identity'], DB::table('auth_identities')->where('user_id', $user->id)->value('id'));
        $this->assertSame($before['nation'], DB::table('nations')->where('id', $nation->id)->value('id'));
        $this->assertSame($before['membership'], DB::table('nation_memberships')->where('nation_id', $nation->id)->value('id'));
        $this->assertSame($before['queue'], DB::table('nation_command_queues')->where('id', $before['queue'])->value('id'));
        $this->assertSame($before['item'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('id'));
        $this->assertSame($before['map_count'], DB::table('map_cells')->count());
        $this->assertSame($before['money'], DB::table('nations')->where('id', $nation->id)->value('money'));
        $this->assertSame($before['population'], DB::table('map_cells')->where('owner_nation_id', $nation->id)->sum('population'));
        $this->assertSame($before['position'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('queue_position'));
        $this->assertSame($before['status'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('status'));
        $this->assertSame($before['request_key'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('request_key'));
        $this->assertSame($before['queued_at'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('queued_at'));
        $this->assertSame($before['created_at'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('created_at'));
        $this->assertSame($before['updated_at'], DB::table('nation_command_queue_items')->where('id', $item->id)->value('updated_at'));
        $this->assertSame(49, NationCommandQueueItem::query()->findOrFail($item->id)->quantity);
        $this->assertSame(['future_key' => 7], NationCommandQueueItem::query()->findOrFail($item->id)->parameters);

        $balances = DB::table('nation_resources')
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->where('nation_resources.nation_id', $nation->id)
            ->pluck('nation_resources.amount', 'resource_definitions.key');
        $this->assertSame(10_000, $balances['wheat']);
        $this->assertSame(300, $balances['fish']);
        $this->assertSame(1200, $balances['monster_meat']);
        $this->assertSame(7, $balances['industrial_goods']);
    }

    public function test_invalid_legacy_quantity_fails_before_adding_the_column(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('invalid-quantity-user', 'Invalid Quantity User'),
        );
        $nation = app(NationCreationService::class)->create($user, $world, '不正数量国');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            $user, $nation, $mapSpace, 'land_clear', $target->x, $target->y, (string) Str::uuid(), 1,
        )['item'];
        $migration = require database_path('migrations/2026_07_28_000000_add_universal_quantity_to_command_queue_items.php');
        $migration->down();
        foreach ([null, 0, -1, 100, 1.5, '3', true, [1], ['nested' => 1]] as $invalid) {
            DB::table('nation_command_queue_items')->where('id', $item->id)->update([
                'parameters' => json_encode(['quantity' => $invalid], JSON_THROW_ON_ERROR),
            ]);

            try {
                $migration->up();
                $this->fail('Expected invalid legacy quantity failure.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("id={$item->id}", $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('nation_command_queue_items', 'quantity'));
            }
        }

        DB::table('nation_command_queue_items')->where('id', $item->id)->update([
            'parameters' => json_encode(['quantity' => 1], JSON_THROW_ON_ERROR),
        ]);
        $migration->up();
    }

    public function test_food_rollback_rejects_non_divisible_balance_without_mutation(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('rollback-food-user', 'Rollback Food User'),
        );
        $nation = app(NationCreationService::class)->create($user, $world, '食料rollback国');
        $wheatId = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $wheatId)->update(['amount' => 10_001]);
        $migration = require database_path('migrations/2026_07_28_010000_normalize_food_resources_to_tons.php');

        try {
            $migration->down();
            $this->fail('Expected lossy rollback rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('balance=10001', $exception->getMessage());
            $this->assertSame(10_001, DB::table('nation_resources')->where('nation_id', $nation->id)
                ->where('resource_definition_id', $wheatId)->value('amount'));
            $this->assertTrue(Schema::hasColumn('resource_definitions', 'unit_label'));
        }
    }
}
