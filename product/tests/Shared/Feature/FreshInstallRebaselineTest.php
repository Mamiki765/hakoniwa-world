<?php

namespace Tests\Shared\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Application\Ver420RulesetUpgrade;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapCell;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class FreshInstallRebaselineTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_current_postgresql_schema_and_v26_catalog_are_installed(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $current = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->install($current);
        app(RulesetPublisher::class)->publish($current);
        $ruleset = RulesetVersion::query()->where('key', Ver420RulesetUpgrade::TARGET_KEY)->sole();

        $this->assertSame([Ver420RulesetUpgrade::TARGET_KEY], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame(Ver420RulesetUpgrade::TARGET_KEY, $ruleset->key);
        $this->assertSame(Ver420RulesetUpgrade::TARGET_VERSION, $ruleset->version);
        $this->assertDatabaseHas('ruleset_versions', [
            'key' => Ver420RulesetUpgrade::SOURCE_KEY,
            'version' => Ver420RulesetUpgrade::SOURCE_VERSION,
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_09_030000_add_surface_paradox_and_daily_rewards',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_12_000000_publish_v24_3_9_2_release',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_13_000000_publish_v25_3_9_3_release',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_13_000000_rebuild_underground_skills_and_store_rental_party',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_15_000000_enable_npc_surface_ships',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_15_010000_add_ocean_loop',
        ]);
        $nationIdColumn = DB::selectOne(<<<'SQL'
SELECT is_nullable
  FROM information_schema.columns
 WHERE table_schema = current_schema() AND table_name = 'ships' AND column_name = 'nation_id'
SQL);
        $this->assertSame('YES', $nationIdColumn?->is_nullable);
        $this->assertTrue(Schema::hasColumn('ships', 'population'));
        $this->assertTrue(Schema::hasTable('buried_treasures'));
        $this->assertFalse(Schema::hasTable('buried_treasure_reveals'));
        $this->assertTrue(Schema::hasColumn('secretary_item_instances', 'resolved_rarity'));
        $this->assertTrue(Schema::hasColumn('secretary_item_instances', 'resolved_fixed_sale_price_money'));
        $this->assertTrue(Schema::hasColumn('announcements', 'body_format'));
        foreach ([
            'user_paradox_balances',
            'user_paradox_ledger',
            'user_daily_login_claims',
            'user_daily_quest_progress',
            'user_daily_quest_activities',
            'compensation_grants',
            'compensation_grant_items',
            'compensation_grant_claims',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing current table {$table}.");
        }
        $this->assertTrue(Schema::hasColumn('nation_command_queue_items', 'paradox_execution_count'));
        $this->assertDatabaseHas('facility_definitions', [
            'key' => 'central_bank',
            'asset_key' => 'tile.central_bank',
            'visibility_policy' => 'disguised',
        ]);
        $this->assertDatabaseHas('facility_definitions', [
            'key' => 'central_granary',
            'asset_key' => 'tile.central_granary',
            'visibility_policy' => 'disguised',
        ]);
        app(CurrentCatalogInstaller::class)->assertInstalled($current);
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
}
