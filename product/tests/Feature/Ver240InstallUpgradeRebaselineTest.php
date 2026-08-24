<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Application\Ver240DormancyRulesetUpgrade;
use App\Application\Ver240KarmaRecoveryRulesetUpgrade;
use App\Application\Ver250MonsterExperienceRulesetUpgrade;
use App\Application\Ver250SecretaryProfileRulesetUpgrade;
use App\Application\Ver260OilResourceRulesetUpgrade;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\NationCommandQueueItem;
use App\Models\NationMonsterKillStat;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;
use Throwable;

final class Ver240InstallUpgradeRebaselineTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const REBASELINE_MIGRATION = '2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade';

    private const MIGRATION = '2026_08_23_000000_add_nation_dormancy_and_publish_v12';

    private const KARMA_MIGRATION = '2026_08_23_010000_add_nation_karma_and_publish_v13';

    private const SECRETARY_PROFILE_MIGRATION = '2026_08_24_000000_add_secretary_profiles_and_publish_v14';

    private const MONSTER_EXPERIENCE_MIGRATION = '2026_08_24_010000_add_monster_experience_and_publish_v15';

    private const OIL_MIGRATION = '2026_08_25_000000_add_oil_resource_and_publish_v16';

    public function test_supported_v11_source_upgrade_preserves_provenance_and_remains_runnable(): void
    {
        [$world, $item, $target] = $this->supportedSourceWithQueuedCommand();
        app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        DB::table('migrations')->whereIn('migration', [
            self::MIGRATION,
            self::KARMA_MIGRATION,
            self::SECRETARY_PROFILE_MIGRATION,
            self::MONSTER_EXPERIENCE_MIGRATION,
            self::OIL_MIGRATION,
        ])->delete();
        $fingerprint = $item->fresh()->request_fingerprint;
        $idleCounter = (int) $world->nations()->sole()->idle_counter;
        $this->assertSame(100, $idleCounter);
        $secretaryDigest = $this->legacySecretaryDigest();

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame($fingerprint, $item->fresh()->request_fingerprint);
        $this->assertSame(100, (int) $world->nations()->sole()->idle_counter);
        $this->assertSame($secretaryDigest, $this->legacySecretaryDigest());
        $secretaryId = (int) DB::table('secretaries')->sole()->id;
        $this->assertDatabaseHas('secretary_skills', [
            'secretary_id' => $secretaryId,
            'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
            'level' => 0,
            'experience' => 0,
        ]);
        $this->assertSame(Ver260OilResourceRulesetUpgrade::TARGET_KEY, $world->fresh()->rulesetVersion()->value('key'));
        $this->assertSame(
            Ver260OilResourceRulesetUpgrade::TARGET_KEY,
            $item->fresh()->definition()->firstOrFail()->rulesetVersion()->value('key'),
        );
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v12_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v13_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v14_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v15_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v16_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        $this->assertDatabaseHas('migrations', ['migration' => self::KARMA_MIGRATION]);
        $this->assertDatabaseHas('migrations', ['migration' => self::SECRETARY_PROFILE_MIGRATION]);
        $this->assertDatabaseHas('migrations', ['migration' => self::MONSTER_EXPERIENCE_MIGRATION]);
        $this->assertDatabaseHas('migrations', ['migration' => self::OIL_MIGRATION]);

        $postUpgradeRun = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $postUpgradeRun->status);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
    }

    public function test_historical_queued_v10_request_without_fingerprint_rebaselines_to_v12_and_remains_runnable(): void
    {
        [$world, $item, $target] = $this->supportedSourceWithQueuedCommand();
        $v10 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        $v11 = RulesetVersion::query()->where('key', Ver240DormancyRulesetUpgrade::SOURCE_KEY)->sole();
        $buildMine = CommandDefinition::query()
            ->where('ruleset_version_id', $v11->id)
            ->where('key', 'build_mine')
            ->sole();
        $target = $target->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->transitionTerrain(
            $target,
            TerrainDefinition::query()->where('key', 'mountain')->sole(),
        );
        $target->save();
        $item->update([
            'command_definition_id' => $buildMine->id,
            'request_ruleset_version_id' => $v10->id,
            'request_fingerprint' => null,
            'queue_position' => 10,
        ]);
        $world->nations()->sole()->update(['money' => 1_000]);
        DB::table('migrations')->whereIn('migration', [
            self::REBASELINE_MIGRATION,
            self::MIGRATION,
            self::KARMA_MIGRATION,
            self::SECRETARY_PROFILE_MIGRATION,
            self::MONSTER_EXPERIENCE_MIGRATION,
            self::OIL_MIGRATION,
        ])->delete();
        $requestKey = $item->fresh()->request_key;

        $this->assertSame(Ver240DormancyRulesetUpgrade::SOURCE_KEY, $item->fresh()->definition->rulesetVersion->key);
        $this->assertSame($v10->id, $item->fresh()->request_ruleset_version_id);
        $this->assertNull($item->fresh()->request_fingerprint);

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertNull($item->fresh()->request_fingerprint);
        $this->assertSame($requestKey, $item->fresh()->request_key);
        $this->assertSame($v10->id, $item->fresh()->request_ruleset_version_id);
        $this->assertSame(10, $item->fresh()->queue_position);
        $this->assertSame(Ver260OilResourceRulesetUpgrade::TARGET_KEY, $item->fresh()->definition->rulesetVersion->key);

        $postUpgradeRun = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $postUpgradeRun->status);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('mine', $target->fresh()->facility()->value('key'));
    }

    public function test_exact_v12_to_v13_preserves_live_state_then_advances_to_v15(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $active = app(NationCreationService::class)->create($owner, $world, 'v13移行国', '移行島主');
        $active->update(['idle_counter' => 137]);
        $dormantOwner = User::factory()->create();
        $dormant = app(NationCreationService::class)->create($dormantOwner, $world, '保存休眠国', '保存島主');
        $dormant->update([
            'state' => 'dormant',
            'state_reason' => 'manual',
            'state_started_turn' => 1,
            'resume_at_turn' => 86,
            'idle_counter' => 777,
        ]);
        $secretary = Secretary::query()->where('user_id', $owner->id)->sole();
        $secretary->update(['name' => '移行秘書', 'named_at' => now()]);
        SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id,
            'item_key' => 'migration_fixture',
            'level' => 3,
            'equipped_slot' => null,
            'grant_key' => 'migration:v12-v13',
            'obtained_at' => now(),
        ]);

        $targets = MapCell::query()->where('owner_nation_id', $active->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $targets);
        $queue = app(CommandQueueService::class);
        $queued = $queue->add(
            user: $owner,
            nation: $active,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $targets[0]->x,
            targetY: $targets[0]->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $terminal = $queue->add(
            user: $owner,
            nation: $active,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $targets[1]->x,
            targetY: $targets[1]->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 2,
        )['item'];
        $historicalRequestRuleset = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        $terminal->update([
            'status' => 'completed',
            'queue_position' => null,
            'request_ruleset_version_id' => $historicalRequestRuleset->id,
            'request_fingerprint' => null,
            'execution_completed_at' => now(),
        ]);

        $monsterCell = MapCell::query()->where('owner_nation_id', $active->id)
            ->whereNull('facility_definition_id')->whereNotIn('id', $targets->modelKeys())
            ->orderBy('id')->firstOrFail();
        $monsterDefinition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'inora')->sole();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $monsterDefinition->id,
            'current_hp' => 2,
            'spawned_max_hp' => 2,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 4,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $monsterCell->id,
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $active->id,
            'monster_definition_id' => $monsterDefinition->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);

        $v12 = $this->attachExactV12($world);
        DB::table('migrations')->where('migration', self::KARMA_MIGRATION)->delete();
        $queuedIdentity = $queued->fresh()->only([
            'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
        ]);
        $terminalHistory = (array) DB::table('nation_command_queue_items')->where('id', $terminal->id)->sole();
        $nationState = DB::table('nations')->orderBy('id')->get([
            'id', 'state', 'state_reason', 'state_started_turn', 'resume_at_turn', 'idle_counter', 'karma',
        ])->map(static fn (object $row): array => (array) $row)->all();
        $secretaryDigest = $this->secretaryDigest();
        $monsterState = $monster->fresh()->only([
            'current_hp', 'spawned_max_hp', 'state', 'spawned_target_turn', 'removal_reason', 'removed_at', 'version',
        ]);
        $occupancy = (array) DB::table('monster_occupancies')->where('monster_instance_id', $monster->id)->sole();
        $killState = $killStat->fresh()->only([
            'world_id', 'nation_id', 'kill_count', 'first_killed_turn', 'last_killed_turn', 'version',
        ]);
        $auditCutoff = (int) DB::table('audit_events')->max('id');
        $auditHistory = DB::table('audit_events')->where('id', '<=', $auditCutoff)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::KARMA_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(Ver240KarmaRecoveryRulesetUpgrade::TARGET_KEY, $world->fresh()->rulesetVersion()->value('key'));
        $this->assertSame(Ver240KarmaRecoveryRulesetUpgrade::TARGET_KEY, $queued->fresh()->definition->rulesetVersion->key);
        $this->assertSame($v12->id, $terminal->fresh()->command_definition_id === null
            ? null
            : $terminal->fresh()->definition->ruleset_version_id);
        $this->assertSame($queuedIdentity, $queued->fresh()->only(array_keys($queuedIdentity)));
        $this->assertSame($terminalHistory, (array) DB::table('nation_command_queue_items')->where('id', $terminal->id)->sole());
        $this->assertSame($nationState, DB::table('nations')->orderBy('id')->get([
            'id', 'state', 'state_reason', 'state_started_turn', 'resume_at_turn', 'idle_counter', 'karma',
        ])->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame($secretaryDigest, $this->secretaryDigest());
        $this->assertSame($monsterState, $monster->fresh()->only(array_keys($monsterState)));
        $this->assertSame($occupancy, (array) DB::table('monster_occupancies')->where('monster_instance_id', $monster->id)->sole());
        $this->assertSame($killState, $killStat->fresh()->only(array_keys($killState)));
        $this->assertSame($auditHistory, DB::table('audit_events')->where('id', '<=', $auditCutoff)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame(Ver240KarmaRecoveryRulesetUpgrade::TARGET_KEY, $monster->fresh()->definition->rulesetVersion->key);
        $this->assertSame(Ver240KarmaRecoveryRulesetUpgrade::TARGET_KEY, $killStat->fresh()->definition->rulesetVersion->key);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v13_activated', 'visibility' => 'admin']);

        DB::table('migrations')->where('migration', self::SECRETARY_PROFILE_MIGRATION)->delete();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::SECRETARY_PROFILE_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->assertSame(
            Ver250SecretaryProfileRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );

        DB::table('migrations')->where('migration', self::MONSTER_EXPERIENCE_MIGRATION)->delete();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MONSTER_EXPERIENCE_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->assertSame(
            Ver250MonsterExperienceRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );

        $this->assertSame('queued', $queued->fresh()->status);
    }

    public function test_v13_migration_rejects_a_non_v12_world_before_business_mutation(): void
    {
        $world = $this->lightweightWorld();
        $this->attachExactV12($world);
        $v11 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get(Ver240DormancyRulesetUpgrade::SOURCE_KEY),
        );
        $world->update(['ruleset_version_id' => $v11->id]);
        DB::table('migrations')->where('migration', self::KARMA_MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertKarmaMigrationBlocked('requires the exact supported ver 2.4.0/v12 source');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($v11->id, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('migrations', ['migration' => self::KARMA_MIGRATION]);
    }

    public function test_exact_v13_through_v15_preserves_profile_backfills_one_historical_old_bow_kill_and_reruns_once(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, 'v14移行国', 'v14移行島主');
        $secretary = $owner->secretary()->firstOrFail();
        $secretary->update([
            'name' => 'v14移行秘書',
            'named_at' => now(),
            'profile_biography' => "移行前の経歴\n2行目",
            'main_image_path' => str_repeat('a', 64).'.png',
            'main_image_mime_type' => 'image/png',
            'main_image_creation_method' => 'commissioned_or_permitted',
            'main_image_credit' => '移行前作者',
            'main_image_updated_at' => now(),
        ]);
        $owner->forceFill([
            'show_ai_generated_secretary_images' => false,
            'secretary_image_fallback' => 'peridot',
        ])->save();
        $this->attachExactV13($world);
        DB::table('migrations')->where('migration', self::SECRETARY_PROFILE_MIGRATION)->delete();
        $secretaryState = $this->secretaryDigest();
        $userState = $owner->fresh()->only([
            'show_ai_generated_secretary_images', 'secretary_image_fallback',
        ]);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::SECRETARY_PROFILE_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(
            Ver250SecretaryProfileRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );
        $this->assertSame($secretaryState, $this->secretaryDigest());
        $this->assertSame($userState, $owner->fresh()->only(array_keys($userState)));
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'ruleset.v14_activated',
            'visibility' => 'admin',
        ]);
        $legacySkillValues = [
            SecretarySkillCatalog::AGRICULTURAL_POLICY => ['level' => 2, 'experience' => 3],
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => ['level' => 4, 'experience' => 5],
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => ['level' => 6, 'experience' => 7],
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => ['level' => 8, 'experience' => 9],
        ];
        foreach ($legacySkillValues as $skillKey => $values) {
            $secretary->skills()->where('skill_key', $skillKey)->update($values);
        }

        $historicalBase = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->whereIn('key', ['plain', 'wasteland']))
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $historicalBase,
            FacilityDefinition::query()->where('key', 'missile_base')->firstOrFail(),
            experience: 73,
        );
        $historicalBase->save();

        DB::table('migrations')->where('migration', self::MONSTER_EXPERIENCE_MIGRATION)->delete();
        $unresolved = TurnRun::query()->create($this->turnRunState(
            $world->id,
            $world->fresh()->ruleset_version_id,
            TurnRun::STATUS_PENDING,
            false,
        ));
        $this->assertMonsterExperienceMigrationBlocked('status pending');
        $this->assertSame(
            Ver250SecretaryProfileRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );
        $this->assertSame(73, (int) $historicalBase->fresh()->facility_experience);
        $this->assertDatabaseMissing('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
        ]);
        $unresolved->delete();

        $v14Definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->fresh()->ruleset_version_id)
            ->where('key', 'king_inora')->sole();
        $historicalMonster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $v14Definition->id,
            'current_hp' => 0,
            'spawned_max_hp' => (int) $v14Definition->base_hp,
            'state' => 'killed',
            'spawned_target_turn' => 1,
            'version' => 2,
            'removal_reason' => 'secretary_old_bow',
            'removed_at' => now(),
        ]);
        $validMetadata = [
            'monster_instance_id' => $historicalMonster->id,
            'monster_definition_key' => $v14Definition->key,
            'killer_nation_id' => $nation->id,
            'damage_type' => 'secretary_old_bow',
            'before_hp' => 1,
            'after_hp' => 0,
        ];
        $malformedMetadata = $validMetadata;
        $malformedMetadata['monster_definition_key'] = 'ambiguous-history';
        $now = now();
        $killEventId = DB::table('audit_events')->insertGetId([
            'actor_user_id' => null,
            'world_id' => $world->id,
            'turn' => 1,
            'nation_id' => $nation->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'monster.killed',
            'severity' => 'info',
            'subject_type' => MonsterInstance::class,
            'subject_id' => $historicalMonster->id,
            'metadata' => json_encode($malformedMetadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('audit_events')->insert([
            'actor_user_id' => null,
            'world_id' => $world->id,
            'turn' => 1,
            'nation_id' => $nation->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'monster.damaged',
            'severity' => 'info',
            'subject_type' => MonsterInstance::class,
            'subject_id' => $historicalMonster->id,
            'metadata' => json_encode([
                ...$validMetadata,
                'before_hp' => 2,
                'after_hp' => 1,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('migrations')->where('migration', self::MONSTER_EXPERIENCE_MIGRATION)->delete();

        $this->assertMonsterExperienceMigrationBlocked('cannot be attributed to exactly one historical Secretary');
        $this->assertSame(0, (int) $secretary->fresh()->monster_experience);
        $this->assertDatabaseMissing('secretary_skills', [
            'secretary_id' => $secretary->id,
            'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
        ]);
        $this->assertSame(
            Ver250SecretaryProfileRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );
        $this->assertDatabaseMissing('migrations', ['migration' => self::MONSTER_EXPERIENCE_MIGRATION]);

        DB::table('audit_events')->where('id', $killEventId)->update([
            'metadata' => json_encode($validMetadata, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MONSTER_EXPERIENCE_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $expectedBackfill = (int) $v14Definition->missile_base_experience;
        $this->assertSame(Ver250MonsterExperienceRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'));
        $this->assertSame($expectedBackfill, (int) $secretary->fresh()->monster_experience);
        $this->assertSame(73, (int) $historicalBase->fresh()->facility_experience);
        $skills = $secretary->skills()->get()->keyBy('skill_key');
        $this->assertSame(
            collect(SecretarySkillCatalog::KEYS)->sort()->values()->all(),
            $skills->keys()->sort()->values()->all(),
        );
        foreach ($legacySkillValues as $skillKey => $values) {
            $this->assertSame($values['level'], (int) $skills[$skillKey]->level);
            $this->assertSame($values['experience'], (int) $skills[$skillKey]->experience);
        }
        $this->assertSame(0, (int) $skills[SecretarySkillCatalog::FOREST_MANAGEMENT]->level);
        $this->assertSame(0, (int) $skills[SecretarySkillCatalog::FOREST_MANAGEMENT]->experience);
        $activation = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'ruleset.v15_activated')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $activation['old_bow_kill_count']);
        $this->assertSame(1, $activation['old_bow_secretary_count']);
        $this->assertSame($expectedBackfill, $activation['old_bow_monster_experience_total']);
        $this->assertTrue($activation['historical_facility_experience_preserved']);
        $this->assertSame(1, $activation['forest_management_skill_rows_added']);
        $this->assertTrue($activation['existing_secretary_skills_preserved']);
        $this->assertFalse($activation['historical_forest_management_backfill']);
        $this->assertNull($v14Definition->fresh()->experience_per_damage);

        DB::table('migrations')->where('migration', self::MONSTER_EXPERIENCE_MIGRATION)->delete();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MONSTER_EXPERIENCE_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->assertSame($expectedBackfill, (int) $secretary->fresh()->monster_experience);
        $this->assertSame(73, (int) $historicalBase->fresh()->facility_experience);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v15_activated')->count());

    }

    public function test_exact_v15_to_v16_adds_zero_oil_without_changing_existing_resource_state(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v16移行国',
            'v16移行島主',
        );
        $industrialGoods = ResourceDefinition::query()->where('key', 'industrial_goods')->sole();
        DB::table('nation_resources')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $industrialGoods->id)->update(['amount' => 1_234]);
        DB::table('nation_resource_sale_policies')->where('nation_id', $nation->id)
            ->where('resource_definition_id', $industrialGoods->id)->update([
                'policy' => 'keep_amount',
                'keep_amount' => 222,
                'version' => 2,
            ]);
        $v15 = $this->attachExactV15($world);
        $beforeBalances = $this->nonOilResourceState($nation->id, 'nation_resources', ['amount']);
        $beforePolicies = $this->nonOilResourceState(
            $nation->id,
            'nation_resource_sale_policies',
            ['policy', 'keep_amount', 'version'],
        );
        $this->removeOilCatalogState();
        DB::table('migrations')->where('migration', self::OIL_MIGRATION)->delete();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::OIL_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(
            Ver260OilResourceRulesetUpgrade::TARGET_KEY,
            $world->fresh()->rulesetVersion()->value('key'),
        );
        $this->assertSame($v15->id, app(RulesetPublisher::class)->assertPublished(
            require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v15.php'),
        )->id);
        $oil = ResourceDefinition::query()->where('key', 'oil')->sole();
        $this->assertSame(['石油', 'energy', 'ten_thousand_barrels', '万バレル', true, true, 'sale.oil'], [
            $oil->name, $oil->category, $oil->unit, $oil->unit_label,
            $oil->storable, $oil->tradable, $oil->sale_price_key,
        ]);
        $this->assertDatabaseHas('nation_resources', [
            'nation_id' => $nation->id,
            'resource_definition_id' => $oil->id,
            'amount' => 0,
        ]);
        $this->assertDatabaseHas('nation_resource_sale_policies', [
            'nation_id' => $nation->id,
            'resource_definition_id' => $oil->id,
            'policy' => 'stockpile',
            'keep_amount' => null,
            'version' => 1,
        ]);
        $this->assertSame($beforeBalances,
            $this->nonOilResourceState($nation->id, 'nation_resources', ['amount']));
        $this->assertSame($beforePolicies, $this->nonOilResourceState(
            $nation->id,
            'nation_resource_sale_policies',
            ['policy', 'keep_amount', 'version'],
        ));
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'ruleset.v16_activated',
            'visibility' => 'admin',
            'world_id' => $world->id,
        ]);
        $run = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_every_global_unresolved_non_dry_status_rejects_without_partial_mutation(string $status): void
    {
        $world = $this->exactV11World();
        TurnRun::query()->create($this->turnRunState($world->id, $world->ruleset_version_id, $status, false));
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked("status {$status}");

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    public function test_dry_run_status_is_excluded_from_upgrade_cutoff(): void
    {
        $world = $this->exactV11World();
        TurnRun::query()->create($this->turnRunState(
            $world->id,
            $world->ruleset_version_id,
            TurnRun::STATUS_FAILED,
            true,
        ));
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    public function test_missing_exact_source_migration_is_rejected_without_partial_mutation(): void
    {
        $this->exactV11World();
        DB::table('migrations')->whereIn('migration', [
            Ver240DormancyRulesetUpgrade::SOURCE_MIGRATION,
            self::MIGRATION,
        ])->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('requires the exact supported ver 2.4.0/v11 source');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_non_v11_world_is_rejected_without_ruleset_rebind_or_data_mutation(): void
    {
        $world = $this->lightweightWorld();
        app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v11'),
        );
        $v10 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        $world->update(['ruleset_version_id' => $v10->id]);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('requires the exact supported ver 2.4.0/v11 source');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($v10->id, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_conflicting_v11_payload_is_rejected_without_repair(): void
    {
        $world = $this->exactV11World();
        $v11 = RulesetVersion::query()->where('key', Ver240DormancyRulesetUpgrade::SOURCE_KEY)->sole();
        $settings = $v11->settings;
        $settings['initial_money']++;
        $v11->update(['settings' => $settings]);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('different immutable payload');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($settings, $v11->fresh()->settings);
        $this->assertSame($v11->id, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    /** @return array{World, NationCommandQueueItem, MapCell} */
    private function supportedSourceWithQueuedCommand(): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '保持国', '保持島主');
        $nation->update(['idle_counter' => 100]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $this->attachExactV11($world);

        return [$world, $item, $target];
    }

    private function exactV11World(): World
    {
        $world = $this->lightweightWorld();
        $this->attachExactV11($world);

        return $world;
    }

    private function attachExactV15(World $world): RulesetVersion
    {
        $v15 = app(RulesetPublisher::class)->publish(
            require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v15.php'),
        );
        $world->update(['ruleset_version_id' => $v15->id]);

        return $v15;
    }

    private function attachExactV11(World $world): void
    {
        $v11 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get(Ver240DormancyRulesetUpgrade::SOURCE_KEY),
        );
        $this->removeOilCatalogState();
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FOREST_MANAGEMENT)->delete();
        DB::transaction(function () use ($world, $v11): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            DB::update(<<<'SQL'
UPDATE nation_command_queue_items AS item
SET command_definition_id = target.id
FROM command_definitions AS source,
     command_definitions AS target,
     nation_command_queues AS queue,
     nations AS nation
WHERE source.id = item.command_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND queue.id = item.nation_command_queue_id
  AND nation.id = queue.nation_id
  AND nation.world_id = ?
SQL, [$v11->id, $world->id]);
            $world->update(['ruleset_version_id' => $v11->id]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
    }

    private function attachExactV12(World $world): RulesetVersion
    {
        $v12 = app(RulesetPublisher::class)->publish(
            require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v12.php'),
        );
        $this->removeOilCatalogState();
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FOREST_MANAGEMENT)->delete();
        DB::transaction(function () use ($world, $v12): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER monster_instance_world_ruleset_guard');
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER nation_monster_kill_stat_guard');
            DB::update(<<<'SQL'
UPDATE nation_command_queue_items AS item
SET command_definition_id = target.id
FROM command_definitions AS source,
     command_definitions AS target,
     nation_command_queues AS queue,
     nations AS nation
WHERE source.id = item.command_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND queue.id = item.nation_command_queue_id
  AND nation.id = queue.nation_id
  AND nation.world_id = ?
SQL, [$v12->id, $world->id]);
            DB::update(<<<'SQL'
UPDATE monster_instances AS instance
SET monster_definition_id = target.id
FROM monster_definitions AS source,
     monster_definitions AS target
WHERE source.id = instance.monster_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND instance.world_id = ?
SQL, [$v12->id, $world->id]);
            DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats AS stat
SET monster_definition_id = target.id
FROM monster_definitions AS source,
     monster_definitions AS target
WHERE source.id = stat.monster_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND stat.world_id = ?
SQL, [$v12->id, $world->id]);
            $world->update(['ruleset_version_id' => $v12->id]);
            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER monster_instance_world_ruleset_guard');
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER nation_monster_kill_stat_guard');
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });

        return $v12;
    }

    private function attachExactV13(World $world): RulesetVersion
    {
        $v13 = app(RulesetPublisher::class)->publish(
            require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v13.php'),
        );
        $this->removeOilCatalogState();
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FOREST_MANAGEMENT)->delete();
        DB::transaction(function () use ($world, $v13): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER monster_instance_world_ruleset_guard');
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER nation_monster_kill_stat_guard');
            DB::update(<<<'SQL'
UPDATE nation_command_queue_items AS item
SET command_definition_id = target.id
FROM command_definitions AS source,
     command_definitions AS target,
     nation_command_queues AS queue,
     nations AS nation
WHERE source.id = item.command_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND queue.id = item.nation_command_queue_id
  AND nation.id = queue.nation_id
  AND nation.world_id = ?
  AND item.status = 'queued'
SQL, [$v13->id, $world->id]);
            DB::update(<<<'SQL'
UPDATE monster_instances AS instance
SET monster_definition_id = target.id
FROM monster_definitions AS source,
     monster_definitions AS target
WHERE source.id = instance.monster_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND instance.world_id = ?
  AND instance.state = 'alive'
SQL, [$v13->id, $world->id]);
            DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats AS stat
SET monster_definition_id = target.id
FROM monster_definitions AS source,
     monster_definitions AS target
WHERE source.id = stat.monster_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND stat.world_id = ?
SQL, [$v13->id, $world->id]);
            $world->update(['ruleset_version_id' => $v13->id]);
            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER monster_instance_world_ruleset_guard');
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER nation_monster_kill_stat_guard');
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });

        return $v13;
    }

    private function removeOilCatalogState(): void
    {
        $oilId = DB::table('resource_definitions')->where('key', 'oil')->value('id');
        if ($oilId === null) {
            return;
        }

        DB::table('nation_resource_sale_policies')->where('resource_definition_id', $oilId)->delete();
        DB::table('nation_resources')->where('resource_definition_id', $oilId)->delete();
        DB::table('resource_definitions')->where('id', $oilId)->delete();
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function nonOilResourceState(int $nationId, string $table, array $columns): array
    {
        $select = ['definition.key'];
        foreach ($columns as $column) {
            $select[] = "state.{$column}";
        }

        return DB::table("{$table} as state")
            ->join('resource_definitions as definition', 'definition.id', '=', 'state.resource_definition_id')
            ->where('state.nation_id', $nationId)
            ->where('definition.key', '<>', 'oil')
            ->orderBy('definition.key')
            ->get($select)
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    private function secretaryDigest(): string
    {
        return hash('sha256', json_encode([
            'secretaries' => DB::table('secretaries')->orderBy('id')->get()->all(),
            'skills' => DB::table('secretary_skills')->orderBy('id')->get()->all(),
            'items' => DB::table('secretary_item_instances')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function legacySecretaryDigest(): string
    {
        return hash('sha256', json_encode([
            'secretaries' => DB::table('secretaries')->orderBy('id')->get()->all(),
            'skills' => DB::table('secretary_skills')
                ->whereIn('skill_key', SecretarySkillCatalog::V14_KEYS)
                ->orderBy('id')->get()->all(),
            'items' => DB::table('secretary_item_instances')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, string> */
    private function businessSnapshot(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn (string $table): bool => ! in_array($table, ['cache', 'cache_locks', 'migrations', 'sessions'], true),
        ));
        sort($tables, SORT_STRING);
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(
                static fn (object $row): array => (array) $row,
            )->all();
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $snapshot;
    }

    private function assertMigrationBlocked(string $expectedMessage): void
    {
        try {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/'.self::MIGRATION.'.php',
                '--force' => true,
                '--no-interaction' => true,
            ])->execute();
            $this->fail('Expected the ver 2.4.0 migration preflight to block the upgrade.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    private function assertKarmaMigrationBlocked(string $expectedMessage): void
    {
        try {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/'.self::KARMA_MIGRATION.'.php',
                '--force' => true,
                '--no-interaction' => true,
            ])->execute();
            $this->fail('Expected the exact v12 to v13 migration preflight to block the upgrade.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    private function assertMonsterExperienceMigrationBlocked(string $expectedMessage): void
    {
        try {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/'.self::MONSTER_EXPERIENCE_MIGRATION.'.php',
                '--force' => true,
                '--no-interaction' => true,
            ])->execute();
            $this->fail('Expected the exact v14 to v15 migration preflight to block the upgrade.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function turnRunState(int $worldId, int $rulesetId, string $status, bool $dryRun): array
    {
        return [
            'world_id' => $worldId,
            'target_turn' => 2,
            'ruleset_version_id' => $rulesetId,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => $dryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ];
    }
}
