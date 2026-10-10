<?php

namespace Tests\Shared\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\CurrentDatabaseBaseline;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
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

    public function test_current_postgresql_schema_and_catalog_are_installed(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $current = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->install($current);
        app(RulesetPublisher::class)->publish($current);
        $ruleset = RulesetVersion::query()->where('key', $current['key'])->sole();

        $this->assertArrayHasKey($current['key'], config('hakoniwa.published_rulesets'));
        $this->assertSame($current['key'], $ruleset->key);
        $this->assertSame($current['version'], $ruleset->version);
        $this->assertSame([
            CurrentDatabaseBaseline::MIGRATION,
            '2026_10_03_000000_publish_power_economy_v28',
            '2026_10_04_000000_publish_pizzeria_maintenance_removal_v29',
            '2026_10_04_010000_publish_oil_and_fleet_skills_v30',
            '2026_10_04_020000_add_yunagi_harbor_intro_completion',
            '2026_10_04_020000_publish_sea_area_weather_v31',
            '2026_10_04_030000_add_mad_moon_event_progress',
            '2026_10_06_000000_publish_natural_fire_targets_v32',
            '2026_10_06_010000_add_user_achievements',
            '2026_10_07_000000_publish_ship_visibility_v34',
            '2026_10_07_010000_add_equipment_quality',
            '2026_10_07_020000_allow_abandoned_nation_name_reuse',
            '2026_10_07_030000_publish_undersea_fire_station_v35',
            '2026_10_09_000000_publish_spp_karma_v36',
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        $this->assertSame(['hakoniwa-2s-plus-v28', 'hakoniwa-2s-plus-v29', 'hakoniwa-2s-plus-v30', 'hakoniwa-2s-plus-v31', 'hakoniwa-2s-plus-v32', 'hakoniwa-2s-plus-v33', 'hakoniwa-2s-plus-v34', 'hakoniwa-2s-plus-v35', $current['key']], RulesetVersion::query()->orderBy('version')->pluck('key')->all());
        // The immutable baseline's nine-skill CHECK is verified at its own
        // adoption boundary. This current install includes the v28 extension.
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
            'user_achievements',
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
        $this->assertSame(1, $secretary->surfaceState->equipment_version);
        $starter = SecretaryItemInstance::query()->where('secretary_id', $secretary->id)->sole();
        $this->assertFalse($starter->is_escrowed);
        $this->assertSame('old_bow', $starter->item_key);
        $this->assertSame(1, $starter->equipped_slot);
    }
}
