<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Application\Ver250MonsterExperienceRulesetUpgrade;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\World\WorldGenerationProfile;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\ProductionDefinition;
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

    public function test_empty_postgresql_uses_direct_current_schema_and_v15_catalog_baseline(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $ruleset = RulesetVersion::query()->where('key', Ver250MonsterExperienceRulesetUpgrade::TARGET_KEY)->sole();

        $this->assertSame('2.5.0-beta', config('hakoniwa.application_version'));
        $this->assertSame([Ver250MonsterExperienceRulesetUpgrade::TARGET_KEY], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame(Ver250MonsterExperienceRulesetUpgrade::TARGET_KEY, $ruleset->key);
        $this->assertSame(Ver250MonsterExperienceRulesetUpgrade::TARGET_VERSION, $ruleset->version);
        $this->assertSame(25, CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(3, ProductionDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(10, MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)->count());
        $this->assertSame(51, DB::table('migrations')->count());
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
        $this->assertTrue(Schema::hasColumn('nations', 'karma'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'profile_biography'));
        $this->assertTrue(Schema::hasColumn('users', 'show_ai_generated_secretary_images'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'monster_experience'));
        $this->assertTrue(Schema::hasColumn('monster_definitions', 'experience_per_damage'));
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'secretaries_monster_experience_non_negative')->count());
        $this->assertSame(1, DB::table('pg_constraint')
            ->where('conname', 'monster_definitions_experience_per_damage_non_negative')->count());
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
        $this->assertSame('old_bow', $starter->item_key);
        $this->assertSame(1, $starter->equipped_slot);
    }
}
