<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Application\Ver270SecretaryItemRulesetUpgrade;
use App\Application\Ver280UnderseaCityRulesetUpgrade;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Domain\World\WorldGenerationProfile;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\NationCommandQueueItem;
use App\Models\NationMonsterKillStat;
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
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FreshInstallRebaselineTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    /** @var list<string> */
    private const UNDERGROUND_RELEASE_TABLES = [
        'underground_profiles',
        'underground_trial_progress',
        'underground_trial_runs',
        'underground_battles',
        'underground_battle_logs',
        'underground_intro_progress',
        'underground_intro_requests',
        'underground_skill_allocations',
        'underground_owned_equipment',
    ];

    public function test_empty_postgresql_uses_direct_current_schema_and_v18_catalog_baseline(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $current = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->install($current);
        app(RulesetPublisher::class)->publish($current);
        $ruleset = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v18')->sole();

        $this->assertSame('3.0.0', config('hakoniwa.application_version'));
        $this->assertSame(['hakoniwa-2s-plus-v18'], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame('hakoniwa-2s-plus-v18', $ruleset->key);
        $this->assertSame(18, $ruleset->version);
        $this->assertSame(26, CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(3, ProductionDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(10, MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(56, DB::table('migrations')->count());
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
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_26_000000_publish_v17_secretary_item_foundation',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_27_000000_publish_v18_undersea_city',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_30_050000_rebaseline_3_0_0_underground_release',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_31_000000_add_underground_awakening_progression',
        ]);
        $this->assertSame(0, DB::table('migrations')->whereIn('migration', [
            '2026_08_29_000000_create_underground_profiles',
            '2026_08_29_010000_add_underground_runtime_foundation',
            '2026_08_29_020000_add_underground_intro_progress',
            '2026_08_29_030000_pin_underground_trial_content_identity',
            '2026_08_29_040000_cap_underground_battle_log_retention',
            '2026_08_30_000000_add_underground_contract_growth_and_playtest',
            '2026_08_30_010000_cap_underground_battle_log_retention_to_one_hour',
            '2026_08_30_020000_add_underground_growth_stp_foundation',
            '2026_08_30_030000_add_underground_status_and_skill_progression',
            '2026_08_30_040000_add_underground_equipment_progression',
        ])->count());
        $this->assertDatabaseHas('facility_definitions', [
            'key' => 'undersea_city',
            'asset_key' => 'tile.undersea_city',
            'visibility_policy' => 'disguised',
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
        $this->assertCount(13, $ruleset->settings['secretary']['items']);
        $this->assertTrue(Schema::hasColumn('nations', 'karma'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'profile_biography'));
        $this->assertTrue(Schema::hasColumn('users', 'show_ai_generated_secretary_images'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'monster_experience'));
        $this->assertTrue(Schema::hasColumn('monster_definitions', 'experience_per_damage'));
        $this->assertTrue(Schema::hasColumn('secretary_item_instances', 'is_escrowed'));
        $this->assertTrue(Schema::hasColumn('nations', 'population_high_water'));
        $this->assertTrue(Schema::hasTable('auction_listings'));
        $this->assertTrue(Schema::hasTable('auction_bids'));
        $this->assertTrue(Schema::hasTable('underground_profiles'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'unlocked_area_layers'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'combat_level'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'combat_xp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'shard_balance'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'banked_shard_balance'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'current_hp'));
        $this->assertFalse(Schema::hasColumn('underground_profiles', 'current_mp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'next_battle_at'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'underground_contract_completed_at'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'growth_path_key'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'growth_path_identity'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'growth_path_selected_at'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'unspent_stp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'allocated_vitality_stp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'allocated_might_stp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'allocated_finesse_stp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'allocated_spirit_stp'));
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'allocated_agility_stp'));
        $this->assertTrue(Schema::hasTable('underground_trial_progress'));
        $this->assertTrue(Schema::hasTable('underground_trial_runs'));
        $this->assertTrue(Schema::hasColumn('underground_trial_runs', 'trial_content_identity'));
        $this->assertTrue(Schema::hasTable('underground_battles'));
        $this->assertTrue(Schema::hasTable('underground_battle_logs'));
        $this->assertTrue(Schema::hasTable('underground_intro_progress'));
        $this->assertTrue(Schema::hasColumn('underground_intro_progress', 'branch_identity'));
        $this->assertTrue(Schema::hasTable('underground_intro_requests'));
        $this->assertTrue(Schema::hasTable('underground_skill_allocations'));
        $this->assertTrue(Schema::hasTable('underground_owned_equipment'));
        $this->assertTrue(Schema::hasColumn('underground_owned_equipment', 'definition_key'));
        $this->assertTrue(Schema::hasColumn('underground_owned_equipment', 'catalog_identity'));
        $this->assertTrue(Schema::hasColumn('underground_owned_equipment', 'equipped_slot'));
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
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_unlocked_area_layers_non_negative')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_combat_level_positive')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_battles_profile_request_unique')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_intro_requests_profile_request_unique')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_skill_profile_node_unique')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_skill_profile_slot_unique')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_owned_equipment_slot_check')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_equipment_profile_grant_unique')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_growth_path_check')->count());
        $this->assertSame(0, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_combat_level_max_check')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_stp_entitlement_check')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_banked_shard_balance_non_negative')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_profiles_current_hp_positive')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_intro_progress_branch_identity_check')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'underground_trial_runs_content_identity_not_empty')->count());
        $undergroundIndexes = [
            'underground_battle_logs_expires_at_index',
            'underground_battles_profile_finished_at_index',
            'underground_equipment_vault_page_index',
        ];
        $this->assertSame($undergroundIndexes, DB::table('pg_indexes')
            ->whereIn('indexname', $undergroundIndexes)->orderBy('indexname')->pluck('indexname')->all());
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
        $populationHighWaterBefore = (int) $nation->population_high_water;
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
        $terrainChange = DB::table('audit_events')
            ->where('event_type', 'terrain.changed')
            ->where('world_id', $world->id)
            ->where('turn', 2)
            ->where('nation_id', $nation->id)
            ->where('subject_id', $target->id)
            ->whereRaw("metadata->>'command_key' = ?", ['land_clear'])
            ->sole();
        $terrainChangeMetadata = json_decode(
            (string) $terrainChange->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($run->id, $terrainChangeMetadata['turn_run_id']);
        $this->assertSame('forest', $terrainChangeMetadata['from_terrain_key']);
        $this->assertSame('plain', $terrainChangeMetadata['to_terrain_key']);
        $this->assertSame(7, SecretarySkill::query()->where('secretary_id', $secretary->id)->count());
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
            'level' => 0,
            'experience' => 0,
        ]);
        $birthrate = $secretary->skills()->where(
            'skill_key',
            SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
        )->sole();
        $this->assertSame(0, $birthrate->level);
        $this->assertSame(
            (int) $nation->fresh()->population_high_water - $populationHighWaterBefore,
            $birthrate->experience,
        );
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::INDOMITABLE,
            'level' => 0,
            'experience' => 0,
        ]);
        $this->assertSame(1, $secretary->equipment_version);
        $starter = SecretaryItemInstance::query()->where('secretary_id', $secretary->id)->sole();
        $this->assertFalse($starter->is_escrowed);
        $this->assertSame('old_bow', $starter->item_key);
        $this->assertSame(1, $starter->equipped_slot);
    }

    public function test_exact_2_8_0_v18_upgrade_preserves_business_data_and_remains_runnable(): void
    {
        app(RulesetPublisher::class)->publish(
            require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v17.php'),
        );
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

        $this->returnDatabaseToExact280Source();
        $this->assertSame(
            [
                '2026_08_30_050000_rebaseline_3_0_0_underground_release',
                '2026_08_31_000000_add_underground_awakening_progression',
            ],
            $this->pendingMigrations(),
        );
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame([], $this->pendingMigrations());
        $this->assertTrue(Schema::hasTable('underground_profiles'));
        $this->assertTrue(Schema::hasTable('underground_owned_equipment'));
        $this->assertSame(56, DB::table('migrations')->count());
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($rulesetId, $world->fresh()->ruleset_version_id);
        $this->assertSame(Ver280UnderseaCityRulesetUpgrade::TARGET_CHECKSUM, $formalChecksum);
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

    public function test_3_0_0_migration_rejects_every_noncanonical_2_8_0_ledger_before_schema_change(): void
    {
        $this->returnDatabaseToExact280Source();
        $this->assertSame(54, DB::table('migrations')->count());
        $this->assertUndergroundReleaseTablesAbsent();

        DB::table('migrations')->insert([
            'migration' => '2026_08_28_000000_unexpected_source_migration',
            'batch' => 2,
        ]);
        $this->assertReleaseMigrationRejectsCurrentLedger();
        $this->assertUndergroundReleaseTablesAbsent();
        DB::table('migrations')->where(
            'migration',
            '2026_08_28_000000_unexpected_source_migration',
        )->delete();

        $removedProductionMigration = DB::table('migrations')->where(
            'migration',
            '2026_08_25_000000_add_oil_resource_and_publish_v16',
        )->sole();
        DB::table('migrations')->where('id', $removedProductionMigration->id)->delete();
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_27_000000_publish_v18_undersea_city',
        ]);
        $this->assertReleaseMigrationRejectsCurrentLedger();
        $this->assertUndergroundReleaseTablesAbsent();
        DB::table('migrations')->insert([
            'migration' => $removedProductionMigration->migration,
            'batch' => $removedProductionMigration->batch,
        ]);

        DB::table('migrations')->insert([
            'migration' => '2026_08_29_000000_create_underground_profiles',
            'batch' => 3,
        ]);
        $this->assertReleaseMigrationRejectsCurrentLedger();
        $this->assertUndergroundReleaseTablesAbsent();
        DB::table('migrations')->where(
            'migration',
            '2026_08_29_000000_create_underground_profiles',
        )->delete();

        $this->assertSame(54, DB::table('migrations')->count());
        $this->assertSame(
            '2026_08_27_000000_publish_v18_undersea_city',
            DB::table('migrations')->orderByDesc('migration')->value('migration'),
        );
        $this->assertUndergroundReleaseTablesAbsent();
    }

    public function test_exact_v16_to_v17_upgrade_rolls_back_rebinds_by_stable_key_backfills_authoritative_history_and_is_idempotent(): void
    {
        $targetSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v17.php');
        app(RulesetPublisher::class)->publish($targetSettings);
        RulesetVersion::query()->where('key', Ver280UnderseaCityRulesetUpgrade::TARGET_KEY)->delete();
        $target = RulesetVersion::query()->where('key', Ver270SecretaryItemRulesetUpgrade::TARGET_KEY)->sole();
        $target->delete();
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
        config([
            'hakoniwa.ruleset' => $sourceSettings,
            'hakoniwa.published_rulesets' => [$sourceSettings['key'] => $sourceSettings],
        ]);
        app(CurrentCatalogInstaller::class)->install($sourceSettings);
        app(RulesetPublisher::class)->publish($sourceSettings);

        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '移行検証国', '移行島主');
        $space = $this->surfaceMapSpace($world);
        $targetCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $queued = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $targetCell->x,
            targetY: $targetCell->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $sourceRulesetId = $world->ruleset_version_id;
        $sourceMonster = MonsterDefinition::query()->where('ruleset_version_id', $sourceRulesetId)
            ->where('key', 'inora')->sole();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $sourceMonster->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $sourceMonster->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        $currentPopulation = (int) MapCell::query()->where('owner_nation_id', $nation->id)->sum('population');
        $historicalPeak = $currentPopulation + 70_000;
        DB::table('audit_events')->insert([
            'actor_user_id' => null, 'world_id' => $world->id, 'turn' => 1, 'nation_id' => $nation->id,
            'x' => null, 'y' => null, 'message' => null, 'visibility' => 'admin',
            'event_type' => 'turn.summary', 'severity' => 'info', 'subject_type' => null, 'subject_id' => null,
            'metadata' => json_encode(['summary' => ['population' => [
                'start' => $historicalPeak, 'end' => $currentPopulation,
            ]]], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $secretary = $user->secretary()->sole();
        $oldBowId = $secretary->itemInstances()->sole()->id;
        $requestFingerprint = $queued->request_fingerprint;

        config([
            'hakoniwa.ruleset' => $targetSettings,
            'hakoniwa.published_rulesets' => [$targetSettings['key'] => $targetSettings],
        ]);
        try {
            DB::transaction(function (): void {
                app(Ver270SecretaryItemRulesetUpgrade::class)->run();
                throw new RuntimeException('force v17 migration rollback');
            });
            $this->fail('Expected the injected migration rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force v17 migration rollback', $exception->getMessage());
        }
        $this->assertDatabaseMissing('ruleset_versions', ['key' => Ver270SecretaryItemRulesetUpgrade::TARGET_KEY]);
        $this->assertSame($sourceRulesetId, $world->fresh()->ruleset_version_id);
        $this->assertSame(5, $secretary->skills()->count());
        $this->assertSame(0, (int) $nation->fresh()->population_high_water);

        $this->assertSame('production_v16_to_v17', app(Ver270SecretaryItemRulesetUpgrade::class)->run());
        $targetRuleset = RulesetVersion::query()->where('key', Ver270SecretaryItemRulesetUpgrade::TARGET_KEY)->sole();
        $this->assertSame($targetRuleset->id, $world->fresh()->ruleset_version_id);
        $this->assertSame('land_clear', $queued->fresh()->definition()->value('key'));
        $this->assertSame($targetRuleset->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($requestFingerprint, $queued->fresh()->request_fingerprint);
        $this->assertSame($targetRuleset->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($targetRuleset->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($historicalPeak, (int) $nation->fresh()->population_high_water);
        $birthrate = $secretary->skills()->where('skill_key', SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY)->sole();
        $this->assertSame($historicalPeak, $birthrate->experience);
        $expectedBirthrate = app(SecretarySkillProgression::class)->advance(
            $targetSettings['secretary']['skills'][SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY],
            0,
            0,
            $historicalPeak,
        );
        $this->assertSame($expectedBirthrate['level'], $birthrate->level);
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::INDOMITABLE,
            'level' => 3,
            'experience' => 0,
        ]);
        $this->assertSame($oldBowId, $secretary->itemInstances()->sole()->id);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'turn.summary')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v17_activated')->count());

        $this->assertSame('already_current_v17', app(Ver270SecretaryItemRulesetUpgrade::class)->run());
        $this->assertSame(7, $secretary->skills()->count());
        $this->assertSame(1, $secretary->skills()->whereIn('skill_key', [
            SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
            SecretarySkillCatalog::INDOMITABLE,
        ])->where('skill_key', SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v17_activated')->count());
    }

    public function test_exact_v17_to_v18_upgrade_rebinds_queued_definitions_and_preserves_request_provenance(): void
    {
        $targetSettings = config('hakoniwa.ruleset');
        RulesetVersion::query()->where('key', Ver280UnderseaCityRulesetUpgrade::TARGET_KEY)->delete();
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v17.php');
        config([
            'hakoniwa.ruleset' => $sourceSettings,
            'hakoniwa.published_rulesets' => [$sourceSettings['key'] => $sourceSettings],
        ]);
        $source = app(RulesetPublisher::class)->publish($sourceSettings);
        $world = app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '海底都市移行国', '移行島主');
        $space = $this->surfaceMapSpace($world);
        $targetCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $queued = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $targetCell->x,
            targetY: $targetCell->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $requestRulesetId = (int) $queued->request_ruleset_version_id;
        $requestFingerprint = $queued->request_fingerprint;
        $sourceDefinitionId = (int) $queued->command_definition_id;

        config([
            'hakoniwa.ruleset' => $targetSettings,
            'hakoniwa.published_rulesets' => [$targetSettings['key'] => $targetSettings],
        ]);
        $this->assertSame('production_v17_to_v18', app(Ver280UnderseaCityRulesetUpgrade::class)->run());

        $target = RulesetVersion::query()->where('key', Ver280UnderseaCityRulesetUpgrade::TARGET_KEY)->sole();
        $queued->refresh();
        $this->assertSame($target->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($sourceDefinitionId, (int) $queued->command_definition_id);
        $this->assertSame('land_clear', $queued->definition()->value('key'));
        $this->assertSame($target->id, $queued->definition()->value('ruleset_version_id'));
        $this->assertSame($source->id, $requestRulesetId);
        $this->assertSame($requestRulesetId, (int) $queued->request_ruleset_version_id);
        $this->assertSame($requestFingerprint, $queued->request_fingerprint);
        $this->assertDatabaseHas('command_definitions', [
            'ruleset_version_id' => $target->id,
            'key' => 'build_undersea_city',
            'cost_money' => 1000,
        ]);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v18_activated')->count());
        $this->assertSame('already_current_v18', app(Ver280UnderseaCityRulesetUpgrade::class)->run());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v18_activated')->count());
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
            'secretaries', 'secretary_skills', 'secretary_item_instances', 'underground_profiles',
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

    private function returnDatabaseToExact280Source(): void
    {
        foreach (array_reverse(self::UNDERGROUND_RELEASE_TABLES) as $table) {
            Schema::drop($table);
        }
        DB::table('migrations')->whereIn('migration', [
            '2026_08_30_050000_rebaseline_3_0_0_underground_release',
            '2026_08_31_000000_add_underground_awakening_progression',
        ])->delete();
    }

    private function assertUndergroundReleaseTablesAbsent(): void
    {
        foreach (self::UNDERGROUND_RELEASE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must not be created.');
        }
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2026_08_30_050000_rebaseline_3_0_0_underground_release',
        ]);
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2026_08_31_000000_add_underground_awakening_progression',
        ]);
    }

    private function assertReleaseMigrationRejectsCurrentLedger(): void
    {
        $migration = require database_path(
            'migrations/2026_08_30_050000_rebaseline_3_0_0_underground_release.php',
        );
        try {
            $migration->up();
            $this->fail('Expected the 3.0.0 migration to reject the noncanonical source ledger.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The 3.0.0 migration requires the exact 2.8.0 migration ledger.',
                $exception->getMessage(),
            );
        }
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
