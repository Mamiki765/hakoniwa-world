<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\World\WorldGenerationProfile;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\SecretarySkill;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FreshInstallRebaselineTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_empty_postgresql_uses_direct_current_schema_and_v16_catalog_baseline(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $current = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->install($current);
        app(RulesetPublisher::class)->publish($current);
        $ruleset = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v16')->sole();

        $this->assertSame('2.6.1', config('hakoniwa.application_version'));
        $this->assertSame(['hakoniwa-2s-plus-v16'], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame('hakoniwa-2s-plus-v16', $ruleset->key);
        $this->assertSame(16, $ruleset->version);
        $this->assertSame(25, CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(3, ProductionDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(10, MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(52, DB::table('migrations')->count());
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_23_000000_add_nation_dormancy_and_publish_v12',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_23_010000_add_nation_karma_and_publish_v13',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_24_000000_add_secretary_profiles_and_publish_v14',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_24_010000_add_monster_experience_and_publish_v15',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_25_000000_add_oil_resource_and_publish_v16',
        ]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->sole();
        $this->assertSame(['石油', 'energy', 'ten_thousand_barrels', '万バレル', true, true, 'sale.oil', 60], [
            $oil->name, $oil->category, $oil->unit, $oil->unit_label,
            $oil->storable, $oil->tradable, $oil->sale_price_key, $oil->sort_order,
        ]);
        $this->assertSame(5_000, $ruleset->settings['resource_capacities']['oil']);
        $this->assertSame([1, 2], [
            $ruleset->settings['inventory_sale_rates']['oil']['inventory_units'],
            $ruleset->settings['inventory_sale_rates']['oil']['money_units'],
        ]);
        $this->assertSame(500, $ruleset->settings['turn_processing']['oil_field']['production_units']);
        $categoryKeys = array_keys($ruleset->settings['secretary']['item_categories']);
        sort($categoryKeys);
        $this->assertSame(['accessory', 'bow', 'clothing'], $categoryKeys);
        $this->assertSame(99, $ruleset->settings['secretary']['item_categories']['accessory']['max_equipped']);
        $this->assertSame('accessory', $ruleset->settings['secretary']['items']['ring']['category']);
        $this->assertArrayNotHasKey('same_item_max_equipped', $ruleset->settings['secretary']['items']['ring']);
        $this->assertCount(9, $ruleset->settings['secretary']['items']);
        $this->assertTrue(Schema::hasColumn('nations', 'karma'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'profile_biography'));
        $this->assertTrue(Schema::hasColumn('users', 'show_ai_generated_secretary_images'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'monster_experience'));
        $this->assertTrue(Schema::hasColumn('monster_definitions', 'experience_per_damage'));
        $this->assertTrue(Schema::hasColumn('secretary_item_instances', 'is_escrowed'));
        $this->assertTrue(Schema::hasTable('auction_listings'));
        $this->assertTrue(Schema::hasTable('auction_bids'));
        $this->assertSame(0, DB::table('auction_listings')->count());
        $this->assertSame(0, DB::table('auction_bids')->count());
        $this->assertSame(6, $ruleset->settings['trading_post']['npc']['duration_turns']);
        $this->assertSame(['resource' => 3, 'item' => 2], [
            'resource' => $ruleset->settings['trading_post']['npc']['active_resource_limit'],
            'item' => $ruleset->settings['trading_post']['npc']['active_item_limit'],
        ]);
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'secretaries_monster_experience_non_negative')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'monster_definitions_experience_per_damage_non_negative')->count());
        $auctionIndexes = [
            'auction_bids_bidder_index',
            'auction_bids_one_highest_per_listing',
            'auction_listings_active_item_unique',
            'auction_listings_active_seller_index',
            'auction_listings_active_world_end_index',
        ];
        $this->assertSame($auctionIndexes, DB::table('pg_indexes')
            ->whereIn('indexname', $auctionIndexes)->orderBy('indexname')->pluck('indexname')->all());
        $auctionForeignKeys = [
            'auction_bids_auction_listing_id_foreign',
            'auction_bids_bidder_nation_id_foreign',
            'auction_listings_highest_bidder_nation_id_foreign',
            'auction_listings_resource_definition_id_foreign',
            'auction_listings_secretary_item_instance_id_foreign',
            'auction_listings_seller_nation_id_foreign',
            'auction_listings_world_id_foreign',
        ];
        $this->assertSame($auctionForeignKeys, DB::table('pg_constraint')
            ->where('contype', 'f')->whereIn('conname', $auctionForeignKeys)
            ->orderBy('conname')->pluck('conname')->all());
        $integrityTriggers = [
            'monster_instance_world_ruleset_guard',
            'nation_command_queue_items_world_ruleset_match',
            'nation_monster_kill_stat_guard',
        ];
        $this->assertSame($integrityTriggers, DB::table('pg_trigger')
            ->where('tgisinternal', false)->whereIn('tgname', $integrityTriggers)
            ->orderBy('tgname')->pluck('tgname')->all());
        $karmaConstraint = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
  FROM pg_constraint
 WHERE conname = 'nations_karma_range_check'
SQL);
        $this->assertNotNull($karmaConstraint);
        $this->assertStringContainsString('-30', (string) $karmaConstraint->definition);
        $this->assertSame(0, MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->whereNull('experience_per_damage')->count());
        app(CurrentCatalogInstaller::class)->assertInstalled(config('hakoniwa.ruleset'));
    }

    public function test_direct_baseline_supports_world_nation_command_turn_and_secretary_item_initialization(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '新規基準国', '新規基準島主');
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];

        $run = app(TurnRunner::class)->run($world);
        $secretary = Secretary::query()->where('user_id', $user->id)->sole();

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertFalse($run->is_dry_run);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame('completed', NationCommandQueueItem::query()->findOrFail($item->id)->status);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        $this->assertSame(5, SecretarySkill::query()->where('secretary_id', $secretary->id)->count());
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
            'level' => 0,
            'experience' => 0,
        ]);
        $this->assertSame(1, $secretary->equipment_version);
        $starter = SecretaryItemInstance::query()->where('secretary_id', $secretary->id)->sole();
        $this->assertFalse($starter->is_escrowed);
        $this->assertSame('old_bow', $starter->item_key);
        $this->assertSame(1, $starter->equipped_slot);
    }

    public function test_already_current_v16_deployment_is_a_business_data_no_op_and_remains_runnable(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '現行維持国', '現行島主');
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        );
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $rulesetId = $ruleset->id;
        $databasePayloadChecksum = $this->rulesetChecksum($ruleset->settings);
        $formalChecksum = $this->rulesetChecksum(config('hakoniwa.ruleset'));
        $before = $this->businessSnapshot();

        $this->assertSame([], $this->pendingMigrations());
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame([], $this->pendingMigrations());
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($rulesetId, $world->fresh()->ruleset_version_id);
        $this->assertSame(
            '331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d',
            $formalChecksum,
        );
        $this->assertEquals(config('hakoniwa.ruleset'), $ruleset->fresh()->settings);
        app(RulesetPublisher::class)->assertPublished(config('hakoniwa.ruleset'));
        $this->assertSame(
            $databasePayloadChecksum,
            $this->rulesetChecksum($ruleset->fresh()->settings),
        );

        $run = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $world->fresh()->current_turn);
    }

    /** @return array<string, string> */
    private function businessSnapshot(): array
    {
        $tables = [
            'worlds', 'ruleset_versions',
            'terrain_definitions', 'resource_definitions', 'facility_definitions',
            'monument_definitions', 'command_definitions', 'production_definitions',
            'monster_definitions',
            'nations', 'nation_capitals', 'nation_memberships',
            'map_spaces', 'map_chunks', 'map_cells',
            'nation_resources', 'nation_resource_sale_policies',
            'secretaries', 'secretary_skills', 'secretary_item_instances',
            'auction_listings', 'auction_bids',
            'nation_command_queues', 'nation_command_queue_items', 'nation_command_queue_bulk_requests',
            'turn_runs', 'audit_events', 'world_generation_runs',
            'monster_instances', 'monster_occupancies', 'nation_monster_kill_stats',
            'nation_monster_cycle_stats', 'nation_monster_cycle_seed_requirements',
        ];
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(
                static fn (object $row): array => (array) $row,
            )->all();
            $snapshot[$table] = hash(
                'sha256',
                json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            );
        }

        return $snapshot;
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        $files = app('migrator')->getMigrationFiles(database_path('migrations'));
        $ran = app('migrator')->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    /** @param array<string, mixed> $settings */
    private function rulesetChecksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
