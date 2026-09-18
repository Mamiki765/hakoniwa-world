<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundAlphaV1BattleProjector;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundBattleStorage;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Models\SecretaryItemInstance;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundIntroAndPlaytestTest extends UndergroundPlayerAccessTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_tutorial_is_a_legal_deterministic_single_settlement_then_escape_returns_to_secretary(): void
    {
        [$user] = $this->secretaryUser('Tutorial secretary');
        $entryRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => $entryRequest,
        ])->assertOk()->assertJsonPath('data.stage', 'initial_descent');
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => (string) Str::uuid(),
            'action' => 'escape_complete',
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => $entryRequest,
            'action' => 'initial_story_complete',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->advance($user, 'initial_story_complete')->assertJsonPath('data.stage', 'tutorial_ready');

        $tutorialRequest = (string) Str::uuid();
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialRequest,
        ])->assertOk()
            ->assertJsonPath('data.stage', 'escape_pending')
            ->assertJsonPath('data.battle.encounter_name', 'ジャイアントラット')
            ->assertJsonPath('data.battle.context', 'tutorial')
            ->assertJsonPath('data.battle.result', 'victory')
            ->assertJsonPath('data.battle.xp_awarded', 5)
            ->assertJsonPath('data.battle.shard_delta', 0)
            ->assertJsonMissingPath('data.battle.private_seed')
            ->assertJsonMissingPath('data.battle.snapshot');
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialRequest,
        ])->assertOk()->assertExactJson($first->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_tutorial_already_settled');

        $profile = UndergroundProfile::query()->sole();
        $battle = UndergroundBattle::query()->sole();
        $this->assertSame([1, 5, 0, null, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
            $profile->current_hp,
        ]);
        $this->assertArrayNotHasKey('actor', $battle->snapshot);
        $this->assertArrayNotHasKey('loadout', $battle->snapshot);
        $this->assertArrayNotHasKey('enemy', $battle->snapshot);
        $this->assertSame(UndergroundBattleStorage::COMPACTION_VERSION, $battle->compaction_version);
        $this->assertSame('tutorial_giant_rat', $battle->encounter_key);
        $this->assertSame('scripted_solo_summary', $battle->statistics['source']);
        $this->assertSame($battle->damage_dealt, $battle->statistics['self']['damage_dealt']);
        $this->assertEquals(
            ['complete' => true, 'issue_count' => 0, 'reasons' => []],
            $battle->statistics['completeness'],
        );
        $this->assertSame($battle->damage_dealt, $battle->statistics['self']['damage_by_source']['direct']);
        $this->assertNotEmpty($battle->log?->actions);
        $this->assertSame(1, UndergroundBattle::query()->count());
        $this->assertSame(1, UndergroundBattleLog::query()->count());
        $this->assertSame(0, SecretaryItemInstance::query()
            ->whereIn('item_key', ['starter_knife', 'tutorial_starter_knife'])->count());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());

        $this->advance($user, 'escape_complete')->assertJsonPath('data.stage', 'returned_after_tutorial');
        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.underground.combat_level', 1)
            ->assertJsonPath('data.underground.combat_xp', 5)
            ->assertJsonPath('data.underground.next_level_xp', 100);
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stage', 'shopkeeper_encounter');
    }

    public function test_tutorial_contract_mismatch_rolls_back_battle_reward_and_story_stage(): void
    {
        [$user] = $this->secretaryUser('Fail closed secretary');
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'initial_story_complete')
            ->assertJsonPath('data.stage', 'tutorial_ready');
        config(['underground-intro.battles.tutorial.expected_winner' => 'enemy']);
        $requestId = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $requestId,
        ])->assertConflict()->assertJsonPath('code', 'underground_story_combat_contract_failed');

        $profile = UndergroundProfile::query()->sole();
        $this->assertSame([1, 0, 0, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
        ]);
        $this->assertDatabaseHas('underground_intro_progress', [
            'underground_profile_id' => $profile->id,
            'stage' => 'tutorial_ready',
            'tutorial_battle_id' => null,
        ]);
        $this->assertDatabaseMissing('underground_intro_requests', ['request_id' => $requestId]);
        $this->assertSame(0, UndergroundBattle::query()->count());
        $this->assertSame(0, UndergroundBattleLog::query()->count());
    }

    public function test_normal_shopkeeper_name_is_safe_immutable_and_requires_shop_explanation_before_main(): void
    {
        [$user] = $this->secretaryUser('Normal secretary');
        $this->reachShopkeeperNaming($user);
        $twentyGraphemes = str_repeat("e\u{0301}", 20);
        $this->assertSame(
            $twentyGraphemes,
            app(UndergroundIntroCatalog::class)->normalizeShopkeeperName($twentyGraphemes),
        );
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertConflict()->assertJsonPath('code', 'underground_main_locked');
        foreach (["改行\n名", '<b>店員</b>', str_repeat('あ', 21)] as $invalidName) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
                'request_id' => (string) Str::uuid(),
                'name' => $invalidName,
            ])->assertUnprocessable();
        }

        $nameRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => $nameRequest,
            'name' => '  ダミー店員  ',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'shop_explanation')
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員');
        $this->assertDatabaseHas('underground_intro_progress', [
            'shopkeeper_name' => 'ダミー店員',
            'special_loss_required' => false,
            'scripted_loss_battle_id' => null,
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => $nameRequest,
            'name' => '別名',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => (string) Str::uuid(),
            'name' => '別名',
        ])->assertConflict()->assertJsonPath('code', 'underground_shopkeeper_already_named');
        $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_scripted_loss_unavailable');

        $this->advance($user, 'shop_explanation_complete')->assertJsonPath('data.stage', 'contract_ready');
        $this->actingAs($user)->postJson('/api/v1/me/underground/growth-path', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_growth_path_already_selected');
        $this->actingAs($user)->postJson('/api/v1/me/underground/contract', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stage', 'crystal_selection');
        $this->actingAs($user)->postJson('/api/v1/me/underground/growth-path', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'growth_path_selected')
            ->assertJsonPath('data.growth_path.stats.vitality', 18)
            ->assertJsonPath('data.growth_path.stats.might', 34)
            ->assertJsonPath('data.growth_path.max_hp', 492)
            ->assertJsonPath('data.growth_path.max_mp', 10000)
            ->assertJsonPath('data.current_hp', 492)
            ->assertJsonPath('data.growth_path.natural_recovery', 300)
            ->assertJsonPath('data.skill_points_total', 20)
            ->assertJsonPath('data.skill_points_unspent', 20)
            ->assertJsonPath('data.skill_tree_identity', 'secretary-underground-skill-tree-alpha-v2')
            ->assertJsonPath('data.skill_trees.0.label', '戦技')
            ->assertJsonPath('data.skill_trees.0.nodes.0.recommended_stats', ['might', 'finesse'])
            ->assertJsonPath('data.skill_trees.1.label', '護身')
            ->assertJsonPath('data.skill_trees.2.label', '祝福')
            ->assertJsonPath('data.skill_trees.2.nodes.0.recommended_stats', ['spirit'])
            ->assertJsonPath('data.skill_trees.2.nodes.1.recommended_stats', ['spirit'])
            ->assertJsonPath('data.skill_trees.2.nodes.5.recommended_stats', []);
        $playerCatalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $skillAllocation = ['martial_precision_cut' => ['rank' => 1, 'active_slot' => 1]];
        $combatBuildBeforeGuidanceChange = $playerCatalog->playerSkillBuild($skillAllocation, 'dagger');
        $originalGuidance = config('underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats');
        config(['underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats' => ['spirit']]);
        $this->assertSame(
            $combatBuildBeforeGuidanceChange,
            $playerCatalog->playerSkillBuild($skillAllocation, 'dagger'),
        );
        config(['underground-alpha-v1.player_skill_guidance.precision_cut.recommended_stats' => $originalGuidance]);
        $selectedProfile = UndergroundProfile::query()->sole();
        $this->assertDatabaseHas('underground_owned_equipment', [
            'underground_profile_id' => $selectedProfile->id,
            'definition_key' => 'starter_knife',
            'equipped_slot' => 'weapon',
            'grant_key' => 'starter-knife-alpha-v1',
        ]);
        $this->advance($user, 'growth_path_story_complete')->assertJsonPath('data.stage', 'underground_open');
        $nextBattleAt = Carbon::now()->addSeconds(10)->startOfSecond();
        $selectedProfile->update(['next_battle_at' => $nextBattleAt]);
        $main = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員')
            ->assertJsonPath('data.combat_level', 1)
            ->assertJsonPath('data.combat_xp', 5)
            ->assertJsonPath('data.next_battle_at', $nextBattleAt->toAtomString())
            ->assertJsonPath('data.playtest.default_build_key', 'pure_attacker');
        $originalEnvironment = config('app.env');
        config(['app.env' => 'production']);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.playtest', null);
        $this->actingAs($user)->getJson('/api/v1/me/underground/playtest')
            ->assertConflict()
            ->assertJsonPath('code', 'underground_playtest_locked');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            'request_id' => (string) Str::uuid(),
            'build_key' => 'pure_attacker',
            'enemy_key' => 'depth_stalker',
        ])->assertConflict()->assertJsonPath('code', 'underground_playtest_locked');
        $this->assertSame(0, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)->count());
        config(['app.env' => $originalEnvironment]);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $selectedProfile->id)
            ->where('definition_key', 'starter_knife')->count());

        $profile = UndergroundProfile::query()->sole();
        $profile->update(['combat_level' => 2, 'combat_xp' => 100, 'unspent_stp' => 5, 'current_hp' => 400]);
        $stpRequest = (string) Str::uuid();
        $stpPayload = [
            'request_id' => $stpRequest,
            'allocations' => ['vitality' => 2, 'spirit' => 1],
        ];
        $stpResult = $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', $stpPayload)
            ->assertOk()
            ->assertJsonPath('data.unspent_stp', 2)
            ->assertJsonPath('data.allocated_stp.vitality', 2)
            ->assertJsonPath('data.allocated_stp.spirit', 1)
            ->assertJsonPath('data.current_hp', 400)
            ->assertJsonPath('data.status_breakdown.vitality.allocated_stp', 2);
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', $stpPayload)
            ->assertOk()->assertExactJson($stpResult->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            ...$stpPayload,
            'allocations' => ['might' => 1],
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['agility' => 2],
        ])->assertOk()
            ->assertJsonPath('data.unspent_stp', 0)
            ->assertJsonPath('data.allocated_stp.agility', 2)
            ->assertJsonPath('data.status_breakdown.agility.allocated_stp', 2);
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => 1],
        ])->assertConflict()->assertJsonPath('code', 'underground_stp_insufficient');
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['unknown' => 1],
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/underground/status/stp', [
            'request_id' => (string) Str::uuid(),
            'allocations' => ['vitality' => -1],
        ])->assertUnprocessable();

        $skillRequest = (string) Str::uuid();
        $skillPayload = ['request_id' => $skillRequest, 'node_key' => 'miracle_holy_bolt'];
        $skillResult = $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', $skillPayload)
            ->assertOk()
            ->assertJsonPath('data.growth_path.label', '戦技')
            ->assertJsonPath('data.skill_trees.2.label', '祝福')
            ->assertJsonPath('data.skill_trees.2.nodes.0.rank', 1)
            ->assertJsonPath('data.skill_points_unspent', 14)
            ->assertJsonPath('data.skill_points_spent', 6);
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', $skillPayload)
            ->assertOk()->assertExactJson($skillResult->json());
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', null, null, null, null],
        ])->assertOk()
            ->assertJsonPath('data.active_slots.0.key', 'holy_bolt')
            ->assertJsonPath('data.active_slots.0.label', 'ホーリーボルト')
            ->assertJsonCount(5, 'data.active_slots');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_holy_bolt',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_max_rank');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_resurrection',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_prerequisite');
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['mending_prayer', null, null, null, null],
        ])->assertConflict()->assertJsonPath('code', 'underground_active_skill_unacquired');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_mending_prayer',
        ])->assertOk()
            ->assertJsonPath('data.skill_points_unspent', 8)
            ->assertJsonPath('data.skill_points_spent', 12)
            ->assertJsonPath('data.skill_trees.2.nodes.1.rank', 1);
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', 'mending_prayer', null, null, null],
        ])->assertOk()
            ->assertJsonPath('data.active_slots.0.key', 'holy_bolt')
            ->assertJsonPath('data.active_slots.1.key', 'mending_prayer');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_resurrection',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_prerequisite');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_lucid_dream',
        ])->assertOk()
            ->assertJsonPath('data.skill_points_unspent', 2)
            ->assertJsonPath('data.skill_points_spent', 18);
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'miracle_regeneration',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_points_insufficient');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'martial_dagger_flurry',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_prerequisite');
        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'unknown_node',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_node_unknown');
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', 'holy_bolt', null, null, null],
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => ['holy_bolt', null, null, null, null, null],
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/me/underground/skills/loadout', [
            'request_id' => (string) Str::uuid(),
            'slots' => [null, null, null, null, null],
        ])->assertOk()->assertJsonPath('data.active_slots', [null, null, null, null, null]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/skills/acquire', [
            'request_id' => (string) Str::uuid(),
            'node_key' => 'martial_precision_cut',
        ])->assertConflict()->assertJsonPath('code', 'underground_skill_points_insufficient');
        $requestCount = UndergroundIntroRequest::query()->count();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->assertSame($requestCount, UndergroundIntroRequest::query()->count());
        $this->assertSame(1, UndergroundBattle::query()->count());
    }

    public function test_inn_and_bank_use_owned_locked_balances_with_exact_transfer_contracts(): void
    {
        [$user, $secretary] = $this->secretaryUser('Shop secretary');
        [$other, $otherSecretary] = $this->secretaryUser('Other shop secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'shard_balance' => 2350,
            'banked_shard_balance' => 5000,
            'current_hp' => 123,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2',
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        $otherProfile = UndergroundProfile::query()->create([
            'secretary_id' => $otherSecretary->id,
            'shard_balance' => 9000,
            'banked_shard_balance' => 8000,
        ]);

        $trialRun = UndergroundTrialRun::query()->create([
            'underground_profile_id' => $profile->id,
            'run_key' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'trial_content_identity' => 'secretary-underground-trial-01-v2',
            'next_battle_index' => 2,
            'status' => UndergroundTrialRun::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_trial_active');
        $this->assertSame([2350, 123], [
            $profile->fresh()->shard_balance,
            $profile->current_hp,
        ]);
        $trialRun->update([
            'status' => UndergroundTrialRun::STATUS_WITHDRAWN,
            'ended_at' => Carbon::now(),
        ]);

        $innRequest = (string) Str::uuid();
        $inn = $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => $innRequest,
            'secretary_id' => $otherSecretary->id,
            'shard_balance' => 0,
            'banked_shard_balance' => 0,
            'current_hp' => 250,
            'inn_cost' => 0,
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000)
            ->assertJsonPath('data.current_hp', 492);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => $innRequest,
        ])->assertOk()->assertExactJson($inn->json());
        $this->assertSame([9000, 8000], [
            $otherProfile->fresh()->shard_balance,
            $otherProfile->banked_shard_balance,
        ]);

        $profile->update(['shard_balance' => 9, 'banked_shard_balance' => 5000, 'current_hp' => 123]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/inn/rest', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_inn_insufficient_carried_shards');
        $this->assertSame([9, 5000, 123], [
            $profile->fresh()->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
        ]);
        $profile->update(['shard_balance' => 2340, 'banked_shard_balance' => 5000]);

        $transfer = function (string $action, ?int $amount = null) use ($user): TestResponse {
            $payload = [
                'request_id' => (string) Str::uuid(),
                'action' => $action,
            ];
            if ($amount !== null) {
                $payload['amount'] = $amount;
            }

            return $this->actingAs($user)->postJson('/api/v1/me/underground/bank/transfer', $payload);
        };

        $this->actingAs($user)->postJson('/api/v1/me/underground/bank/transfer', [
            'request_id' => (string) Str::uuid(),
            'action' => 'deposit',
            'amount' => 1000,
            'secretary_id' => $otherSecretary->id,
            'shard_balance' => 3000,
            'banked_shard_balance' => 4000,
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', 1340)
            ->assertJsonPath('data.banked_shard_balance', 6000);
        $transfer('withdraw', 1000)->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000);
        $transfer('deposit', 2000)->assertOk()
            ->assertJsonPath('data.shard_balance', 340)
            ->assertJsonPath('data.banked_shard_balance', 7000);
        $transfer('withdraw', 2000)->assertOk()
            ->assertJsonPath('data.shard_balance', 2340)
            ->assertJsonPath('data.banked_shard_balance', 5000);

        foreach ([500, 1500] as $invalidAmount) {
            $transfer('deposit', $invalidAmount)
                ->assertConflict()->assertJsonPath('code', 'underground_bank_amount_invalid');
        }
        $transfer('deposit')->assertConflict()->assertJsonPath('code', 'underground_bank_amount_invalid');
        $transfer('deposit', 0)->assertUnprocessable();
        $transfer('withdraw', -1000)->assertUnprocessable();
        $transfer('deposit', 5000)
            ->assertConflict()->assertJsonPath('code', 'underground_bank_insufficient_carried_shards');
        $transfer('withdraw', 6000)
            ->assertConflict()->assertJsonPath('code', 'underground_bank_insufficient_banked_shards');

        $transfer('deposit_all')->assertOk()
            ->assertJsonPath('data.shard_balance', 0)
            ->assertJsonPath('data.banked_shard_balance', 7340);
        $transfer('withdraw_all')->assertOk()
            ->assertJsonPath('data.shard_balance', 7340)
            ->assertJsonPath('data.banked_shard_balance', 0);
        $this->assertSame([9000, 8000], [
            $otherProfile->fresh()->shard_balance,
            $otherProfile->banked_shard_balance,
        ]);
        $this->assertSame($other->id, $otherSecretary->user_id);
    }

    public function test_respec_combines_growth_stp_and_skill_reset_without_healing_or_rewinding_progress(): void
    {
        [$user, $secretary] = $this->secretaryUser('Respec secretary');
        $profile = $this->openEquipmentProfile($secretary, 1_000, 9_000);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $profile->update([
            'unlocked_area_layers' => 2,
            'combat_level' => 4,
            'combat_xp' => 345,
            'current_hp' => 123,
            'growth_path_key' => 'guardianship_blue',
            'unspent_stp' => 5,
            'allocated_vitality_stp' => 10,
            'skill_points_total' => 60,
            'skill_points_unspent' => 55,
            'awakening_gauge' => 750,
            'awakening_message' => '私がついています！',
            'awakening_technique_key' => 'fortress_strike',
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        $clearedAt = Carbon::now()->subHour();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => $clearedAt->copy()->subHour(),
            'first_cleared_at' => $clearedAt,
        ]);
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->sole();
        $equipmentIds = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($equipmentIds);

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'growth_path_key' => 'free_black',
        ];
        $result = $this->actingAs($user)->postJson('/api/v1/me/underground/respec', $payload)
            ->assertOk()
            ->assertJsonPath('data.stage', 'underground_open')
            ->assertJsonPath('data.growth_path.key', 'free_black')
            ->assertJsonPath('data.shard_balance', 960)
            ->assertJsonPath('data.banked_shard_balance', 9_000)
            ->assertJsonPath('data.combat_level', 4)
            ->assertJsonPath('data.combat_xp', 345)
            ->assertJsonPath('data.current_hp', 123)
            ->assertJsonPath('data.unspent_stp', 18)
            ->assertJsonPath('data.allocated_stp', [
                'vitality' => 0,
                'might' => 0,
                'finesse' => 0,
                'spirit' => 0,
                'agility' => 0,
            ])
            ->assertJsonPath('data.skill_points_total', 60)
            ->assertJsonPath('data.skill_points_unspent', 60)
            ->assertJsonPath('data.active_slots', [null, null, null, null, null])
            ->assertJsonPath('data.respec.cost', 40)
            ->assertJsonPath('data.awakening.current', 750)
            ->assertJsonPath('data.awakening.custom_message', '私がついています！')
            ->assertJsonPath('data.awakening.selected_technique_key', 'limitless_reprise');
        $this->assertNotNull($result->json('data.respec.last_completed_at'));
        $this->assertNotNull($result->json('data.respec.next_available_at'));
        $this->assertSame(
            24 * 60 * 60,
            Carbon::parse((string) $result->json('data.respec.next_available_at'))->getTimestamp()
                - Carbon::parse((string) $result->json('data.respec.last_completed_at'))->getTimestamp(),
        );

        $profile->refresh();
        $this->assertSame([
            2, 4, 345, 960, 9_000, 123, 'free_black', 18, 60, 60, 750, '私がついています！',
        ], [
            $profile->unlocked_area_layers,
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
            $profile->growth_path_key,
            $profile->unspent_stp,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->awakening_gauge,
            $profile->awakening_message,
        ]);
        $this->assertSame(0, array_sum($profile->allocatedStp()));
        $this->assertNotNull($profile->underground_contract_completed_at);
        $this->assertNotNull($profile->last_respec_at);
        $this->assertNull($profile->awakening_technique_key);
        $this->assertSame(0, UndergroundSkillAllocation::query()
            ->where('underground_profile_id', $profile->id)->count());
        $this->assertSame($equipmentIds, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->orderBy('id')
            ->pluck('id')
            ->all());
        $this->assertDatabaseHas('underground_trial_progress', [
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'first_cleared_at' => $clearedAt,
        ]);
        $this->assertDatabaseHas('underground_intro_progress', [
            'id' => $intro->id,
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'tutorial_battle_id' => $intro->tutorial_battle_id,
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', $payload)
            ->assertOk()->assertExactJson($result->json());
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            ...$payload,
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'martial_red',
        ])->assertConflict()->assertJsonPath('code', 'underground_respec_cooldown');
        $this->assertSame(960, $profile->fresh()->shard_balance);
        $this->assertSame(1, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'respec')
            ->count());
    }

    public function test_respec_rejects_invalid_insufficient_and_active_trial_requests_atomically(): void
    {
        [$user, $secretary] = $this->secretaryUser('Respec failure secretary');
        $profile = $this->openEquipmentProfile($secretary, 39, 9_000);
        $profile->update([
            'combat_level' => 4,
            'combat_xp' => 345,
            'current_hp' => 123,
            'unspent_stp' => 5,
            'allocated_vitality_stp' => 10,
            'skill_points_total' => 60,
            'skill_points_unspent' => 55,
        ]);
        $allocation = UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'unknown_path',
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertConflict()->assertJsonPath('code', 'underground_respec_insufficient_carried_shards');

        $profile->update(['shard_balance' => 1_000]);
        UndergroundTrialRun::query()->create([
            'underground_profile_id' => $profile->id,
            'run_key' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'trial_content_identity' => 'secretary-underground-trial-01-v2',
            'next_battle_index' => 2,
            'status' => UndergroundTrialRun::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertConflict()->assertJsonPath('code', 'underground_trial_active');

        $profile->refresh();
        $this->assertSame([
            4, 345, 1_000, 9_000, 123, 'martial_red', 5, 10, 60, 55, null,
        ], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->banked_shard_balance,
            $profile->current_hp,
            $profile->growth_path_key,
            $profile->unspent_stp,
            $profile->allocated_vitality_stp,
            $profile->skill_points_total,
            $profile->skill_points_unspent,
            $profile->last_respec_at,
        ]);
        $this->assertSame(1, UndergroundSkillAllocation::query()->whereKey($allocation->id)->count());
        $this->assertSame(0, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'respec')
            ->count());
    }

    public function test_true_name_branch_runs_one_logged_alpha_v1_scripted_loss_without_normal_penalties(): void
    {
        [$user] = $this->secretaryUser('Special secretary');
        $this->reachShopkeeperNaming($user);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => (string) Str::uuid(),
            'name' => ' リカ ',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'special_loss_pending')
            ->assertJsonPath('data.true_name_branch', true);

        $profile = UndergroundProfile::query()->sole();
        $profile->shard_balance = 9;
        $profile->next_battle_at = Carbon::parse('2026-08-30T00:00:00+09:00');
        $profile->save();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
        ]);
        $profile->refresh();
        $before = [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at?->toAtomString(),
        ];
        $requestId = (string) Str::uuid();
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => $requestId,
        ]);
        $first->assertOk()
            ->assertJsonPath('data.stage', 'special_loss_complete')
            ->assertJsonPath('data.battle.context', 'scripted_loss')
            ->assertJsonPath('data.battle.player_display_name', 'Special secretary')
            ->assertJsonPath('data.battle.encounter_name', 'リカ')
            ->assertJsonPath('data.battle.result', 'defeat')
            ->assertJsonPath('data.battle.rounds', 1)
            ->assertJsonPath('data.battle.xp_awarded', 0)
            ->assertJsonPath('data.battle.shard_delta', 0)
            ->assertJsonPath('data.battle.summary.result', 'defeat')
            ->assertJsonPath('data.battle.summary.player_remaining_hp', 0)
            ->assertJsonPath('data.battle.summary.enemy_remaining_hp', 568_850)
            ->assertJsonPath('data.battle.summary.result', 'defeat')
            ->assertJsonPath('data.battle.summary.damage_received', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.player.max_hp', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.enemy.max_hp', 568_850)
            ->assertJsonStructure(['data' => ['battle' => ['actions' => [
                '*' => ['round', 'actions', 'end_state'],
            ]]]]);
        $firstRoundActions = $first->json('data.battle.actions.0.actions');
        $this->assertIsArray($firstRoundActions);
        $barrierDamage = collect($firstRoundActions)->first(
            static fn (array $action): bool => $action['type'] === 'damage',
        );
        $fatalDamage = collect($firstRoundActions)->first(
            static fn (array $action): bool => $action['type'] === 'counter',
        );
        $this->assertIsArray($barrierDamage);
        $this->assertSame('精密斬り', $barrierDamage['label']);
        $this->assertFalse($barrierDamage['evaded']);
        $this->assertSame(0, $barrierDamage['amount']);
        $this->assertGreaterThan(0, $barrierDamage['barrier_absorbed']);
        $this->assertNull($barrierDamage['agility_combo_hits']);
        $this->assertIsArray($fatalDamage);
        $this->assertSame('反撃', $fatalDamage['label']);
        $this->assertFalse($fatalDamage['evaded']);
        $this->assertGreaterThan(500, $fatalDamage['amount']);
        $this->actingAs($user)->postJson('/api/v1/me/underground/scripted-loss', [
            'request_id' => $requestId,
        ])->assertOk()->assertExactJson($first->json());

        $profile->refresh();
        $this->assertSame($before, [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at?->toAtomString(),
        ]);
        $this->assertSame(1, UndergroundTrialProgress::query()->count());
        $this->assertSame(2, UndergroundBattle::query()->count());
        $this->assertSame(2, UndergroundBattleLog::query()->count());
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_STORY)->count());
        $this->assertNull($profile->current_hp);
        $storyBattle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_STORY)->sole();
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $storyBattle->runtime_identity);
        $this->assertEquals(
            ['complete' => true, 'issue_count' => 0, 'reasons' => []],
            $storyBattle->statistics['completeness'],
        );
        $this->assertSame(1254, $storyBattle->snapshot['enemy_combat_level_equivalent']);
        $this->assertSame(1_137_700, $storyBattle->snapshot['enemy_scale_bps']);
        $storyDefinition = app(UndergroundAlphaV1PlayerCatalog::class)->trueNameStoryBattle();
        $this->assertArrayNotHasKey('ai', $storyBattle->snapshot);
        $this->assertArrayNotHasKey('initial_state', $storyBattle->snapshot);
        $this->assertIsArray($storyBattle->log?->presentation['initial_state']);
        $this->assertSame(UndergroundBattleStorage::COMPACTION_VERSION, $storyBattle->compaction_version);
        $this->assertSame([
            'enemy_unbroken_retort',
            'enemy_renewing_guard',
            'enemy_bulwark_strike',
            'enemy_counter_stance',
            'enemy_shield_bash',
        ], $storyDefinition['catalog']->enemy($storyDefinition['enemy_key'])['skills']);

        $this->advance($user, 'special_loss_aftermath_complete')
            ->assertJsonPath('data.stage', 'shop_explanation');
        $this->advance($user, 'shop_explanation_complete')
            ->assertJsonPath('data.stage', 'contract_ready');
    }

    public function test_hidden_alias_matching_is_exact_and_keeps_the_normalized_display_name(): void
    {
        $catalog = app(UndergroundIntroCatalog::class);
        foreach (['リカ', '雨宮利香', '雨宮 利香', '雨宮　利香', 'リカ・サキュバス'] as $alias) {
            $normalized = $catalog->normalizeShopkeeperName("　{$alias}　");
            $this->assertSame($alias, $normalized);
            $this->assertSame('true_name', $catalog->branchIdentity($normalized));
        }
        $decomposed = \Normalizer::normalize('リカ・サキュバス', \Normalizer::FORM_D);
        $this->assertIsString($decomposed);
        $this->assertNotSame('リカ・サキュバス', $decomposed);
        $this->assertSame($decomposed, $catalog->normalizeShopkeeperName($decomposed));
        $this->assertSame('true_name', $catalog->branchIdentity($decomposed));
        foreach (['エリカ', 'リカちゃん', '雨宮利香さん', '雨 宮利香', '雨宮利 香', 'ダミー'] as $normal) {
            $this->assertSame('normal', $catalog->branchIdentity($normal));
        }
    }

    public function test_rewardless_playtest_is_owner_scoped_and_idempotent(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);

        [$user, $secretary] = $this->secretaryUser('Playtest secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'guardianship_blue',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2',
            'current_hp' => 321,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        $before = $profile->fresh()->only([
            'combat_level', 'combat_xp', 'shard_balance', 'next_battle_at',
            'growth_path_key', 'growth_path_identity', 'growth_path_selected_at',
            'current_hp',
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/underground/playtest')
            ->assertOk()
            ->assertJsonPath('data.default_build_key', 'pure_tank')
            ->assertJsonCount(4, 'data.builds')
            ->assertJsonCount(3, 'data.enemies');
        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'build_key' => 'pure_tank',
            'enemy_key' => 'depth_stalker',
        ];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'playtest')
            ->assertJsonPath('data.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.summary.result', 'victory')
            ->assertJsonPath('data.rewards.xp', 0)
            ->assertJsonPath('data.rewards.shards', 0)
            ->assertJsonPath('data.initial_state.player.mp', AlphaV1CombatRules::MAX_MP)
            ->assertJsonPath('data.rounds.0.start_state', fn (mixed $value): bool => is_array($value))
            ->assertJsonStructure(['data' => [
                'summary' => [
                    'rounds', 'player_remaining_hp', 'enemy_remaining_hp', 'final_mp',
                    'damage_dealt', 'damage_received', 'effective_healing', 'damage_prevented',
                    'mp_spent', 'mp_natural_recovery', 'mp_skill_recovery', 'skill_unavailable_due_to_mp',
                ],
                'rounds' => ['*' => ['round', 'actions', 'start_state', 'end_state']],
            ]])
            ->assertJsonMissingPath('data.private_seed')
            ->assertJsonMissingPath('data.snapshot')
            ->assertJsonMissingPath('data.manifest');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', $payload)
            ->assertOk()->assertExactJson($first->json());
        $secretary->name = 'Renamed secretary';
        $secretary->save();
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'playtest')
            ->assertJsonPath('data.0.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.0.rounds', null)
            ->assertJsonPath('data.0.detail_available', true);
        $detail = $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.context', 'playtest')
            ->assertJsonPath('data.player_display_name', 'Playtest secretary')
            ->assertJsonPath('data.build_name', '護身特化')
            ->assertJsonPath('data.encounter_name', '深層追跡者')
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');
        $this->assertEquals($first->json('data.initial_state'), $detail->json('data.initial_state'));
        $projectedActions = collect($detail->json('data.rounds'))
            ->flatMap(fn (array $round): array => $round['actions']);
        $this->assertTrue($projectedActions->contains(
            fn (array $action): bool => ($action['type'] ?? null) === 'action'
                && ($action['actor_name'] ?? null) === 'Playtest secretary',
        ));
        $this->assertTrue($projectedActions->every(
            fn (array $action): bool => ! array_key_exists('reason', $action)
                && ! array_key_exists('action_key', $action),
        ));
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'build_key' => 'balanced',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
            'build_key' => 'unsupported',
        ])->assertUnprocessable();

        $this->assertEquals($before, $profile->fresh()->only(array_keys($before)));
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)->count());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());

        [$other] = $this->secretaryUser('Locked playtest secretary');
        $this->actingAs($other)->postJson('/api/v1/me/underground/playtest', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_playtest_locked');

        $playtestBattle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_PLAYTEST)
            ->sole();
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $playtestBattle->runtime_identity);
        $this->assertSame(AlphaV1CombatRules::IDENTITY, $playtestBattle->snapshot['combat_rules_identity']);
        $this->assertSame(
            UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION,
            $playtestBattle->snapshot['presentation_log_version'],
        );
        $this->assertArrayNotHasKey('initial_state', $playtestBattle->snapshot);
        $this->assertArrayNotHasKey('ai', $playtestBattle->snapshot);
        $this->assertEquals(
            $first->json('data.initial_state'),
            $playtestBattle->log?->presentation['initial_state'],
        );
        $this->assertEquals(
            ['complete' => true, 'issue_count' => 0, 'reasons' => []],
            $playtestBattle->statistics['completeness'],
        );
        $this->assertSame(1, $playtestBattle->compaction_version);
        $this->assertTrue($playtestBattle->log?->expires_at->equalTo($playtestBattle->finished_at->addHour()) ?? false);
        $legacySnapshot = $playtestBattle->snapshot;
        $legacySnapshot['presentation_log_version'] = 1;
        unset($legacySnapshot['initial_state']);
        $playtestBattle->snapshot = $legacySnapshot;
        $playtestBattle->save();
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.initial_state', null)
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');
        config([
            'underground-alpha-v1.playtest.builds' => [],
            'underground-alpha-v1.playtest.enemies' => [],
        ]);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.build_name', '護身特化')
            ->assertJsonPath('data.encounter_name', '深層追跡者')
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');

        foreach (range(1, 20) as $offset) {
            $copy = $playtestBattle->replicate();
            $copy->request_id = (string) Str::uuid();
            $copy->started_at = $playtestBattle->started_at->subSeconds($offset);
            $copy->finished_at = $playtestBattle->finished_at->subSeconds($offset);
            $copy->save();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $history = $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.build_name', '護身特化')
            ->assertJsonPath('data.0.encounter_name', '深層追跡者');
        $this->assertCount(20, $history->json('data'));
        $beforeCursor = $history->json('data.19.history_cursor');
        $this->assertIsInt($beforeCursor);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles?before_cursor={$beforeCursor}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.history_cursor', fn (mixed $value): bool => is_int($value));
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles?before_cursor=invalid')
            ->assertUnprocessable();
        $this->assertSame([], array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains(
                strtolower((string) ($query['query'] ?? '')),
                'from "underground_battle_logs"',
            ) && ! str_contains(strtolower((string) ($query['query'] ?? '')), 'exists'),
        )));
        DB::disableQueryLog();
    }
}
