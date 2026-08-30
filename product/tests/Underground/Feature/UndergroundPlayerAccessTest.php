<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryService;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class UndergroundPlayerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_routes_require_session_and_resolve_only_the_current_users_secretary(): void
    {
        $this->getJson('/api/v1/me/underground')->assertUnauthorized();
        $this->postJson('/api/v1/me/underground/entry', ['request_id' => (string) Str::uuid()])
            ->assertUnauthorized();

        [$owner, $ownerSecretary] = $this->secretaryUser('Owner secretary');
        [$other, $otherSecretary] = $this->secretaryUser('Other secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
            'secretary_id' => $otherSecretary->id,
        ])->assertOk()->assertJsonPath('data.secretary_name', 'Owner secretary');

        $this->assertDatabaseHas('underground_profiles', ['secretary_id' => $ownerSecretary->id]);
        $this->assertDatabaseMissing('underground_profiles', ['secretary_id' => $otherSecretary->id]);
        $this->actingAs($other)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->assertJsonPath('data.stage', 'not_started');
    }

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
        $this->assertSame([1, 5, 0, null], [
            $profile->combat_level,
            $profile->combat_xp,
            $profile->shard_balance,
            $profile->next_battle_at,
        ]);
        $this->assertSame('tutorial_starter_knife', $battle->snapshot['actor']['weapon_key']);
        $this->assertSame('tutorial_giant_rat', $battle->encounter_key);
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
            ->assertJsonPath('data.growth_path.max_hp', 484)
            ->assertJsonPath('data.growth_path.max_mp', 10000)
            ->assertJsonPath('data.growth_path.natural_recovery', 300);
        $this->advance($user, 'growth_path_story_complete')->assertJsonPath('data.stage', 'underground_open');
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員')
            ->assertJsonPath('data.combat_level', 1)
            ->assertJsonPath('data.combat_xp', 5)
            ->assertJsonPath('data.playtest.default_build_key', 'pure_attacker');
        $requestCount = UndergroundIntroRequest::query()->count();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_intro_stage_conflict');
        $this->assertSame($requestCount, UndergroundIntroRequest::query()->count());
        $this->assertSame(1, UndergroundBattle::query()->count());
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
        ])->assertOk()
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
            ->assertJsonPath('data.battle.summary.damage_dealt', 4)
            ->assertJsonPath('data.battle.summary.damage_received', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.player.max_hp', 500)
            ->assertJsonPath('data.battle.actions.0.end_state.enemy.max_hp', 568_850)
            ->assertJsonStructure(['data' => ['battle' => ['actions' => [
                '*' => ['round', 'actions', 'end_state'],
            ]]]]);
        $storyActions = $first->json('data.battle.actions.0.actions');
        $this->assertIsArray($storyActions);
        $damageIndex = array_search('damage', array_column($storyActions, 'type'), true);
        $stackIndex = array_search('role_stack_gain', array_column($storyActions, 'type'), true);
        $counterIndex = array_search('counter', array_column($storyActions, 'type'), true);
        $this->assertIsInt($damageIndex);
        $this->assertIsInt($stackIndex);
        $this->assertIsInt($counterIndex);
        $this->assertLessThan($stackIndex, $damageIndex);
        $this->assertLessThan($counterIndex, $stackIndex);
        $this->assertSame('counter', $storyActions[array_key_last($storyActions)]['type']);
        $this->assertSame('反撃', $storyActions[array_key_last($storyActions)]['label']);
        $this->assertGreaterThan(500, $storyActions[array_key_last($storyActions)]['amount']);
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
        $storyBattle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_STORY)->sole();
        $this->assertSame('secretary-underground-alpha-v1', $storyBattle->runtime_identity);
        $this->assertSame(1254, $storyBattle->snapshot['enemy_combat_level_equivalent']);
        $this->assertSame(1_137_700, $storyBattle->snapshot['enemy_scale_bps']);
        $storyDefinition = app(UndergroundAlphaV1PlayerCatalog::class)->trueNameStoryBattle();
        $this->assertSame([
            'unbroken_retort',
            'renewing_guard',
            'bulwark_strike',
            'counter_stance',
            'shield_bash',
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

    public function test_growth_catalog_and_rewardless_playtest_are_exact_owner_scoped_and_idempotent(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $paths = collect($catalog->growthPaths())->keyBy('key');
        $this->assertSame([
            'martial_red' => [[18, 34, 30, 8, 10], 484, 'pure_attacker', [1, 2, 1, 1, 0], 5],
            'guardianship_blue' => [[40, 22, 10, 16, 12], 660, 'pure_tank', [2, 1, 1, 1, 0], 5],
            'blessing_green' => [[22, 8, 16, 42, 12], 516, 'pure_healer', [1, 1, 1, 2, 0], 5],
            'free_black' => [[26, 22, 20, 20, 12], 548, 'balanced', [1, 1, 1, 1, 0], 6],
        ], $paths->map(fn (array $path): array => [
            array_values($path['stats']),
            $path['max_hp'],
            $path['default_build_key'],
            array_values($path['natural_growth']),
            $path['unspent_stp_per_level'],
        ])->all());
        foreach ($paths as $path) {
            $this->assertSame(100, array_sum($path['stats']));
            $this->assertSame(10, $path['points_per_level']);
            $this->assertSame(10_000, $path['max_mp']);
            $this->assertSame(300, $path['natural_recovery']);
        }
        foreach (['depth_stalker', 'pressure_construct', 'crystal_warden'] as $enemyKey) {
            $this->assertSame(100, $catalog->playtestDefinition('pure_tank', $enemyKey)['max_rounds']);
        }

        [$user, $secretary] = $this->secretaryUser('Playtest secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'guardianship_blue',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
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
            ->assertJsonStructure(['data' => [
                'summary' => [
                    'rounds', 'player_remaining_hp', 'enemy_remaining_hp', 'final_mp',
                    'damage_dealt', 'damage_received', 'effective_healing', 'damage_prevented',
                    'mp_spent', 'mp_natural_recovery', 'mp_skill_recovery', 'skill_unavailable_due_to_mp',
                ],
                'rounds' => ['*' => ['round', 'actions', 'end_state']],
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
        $this->assertTrue($playtestBattle->log?->expires_at->equalTo($playtestBattle->finished_at->addHour()) ?? false);
        config([
            'underground-alpha-v1.playtest.builds' => [],
            'underground-alpha-v1.playtest.enemies' => [],
        ]);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.build_name', '護身特化')
            ->assertJsonPath('data.encounter_name', '深層追跡者')
            ->assertJsonCount((int) $first->json('data.summary.rounds'), 'data.rounds');

        foreach (range(1, 100) as $offset) {
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
        $this->assertSame([], array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains(
                strtolower((string) ($query['query'] ?? '')),
                'from "underground_battle_logs"',
            ) && ! str_contains(strtolower((string) ($query['query'] ?? '')), 'exists'),
        )));
        DB::disableQueryLog();
    }

    public function test_refresh_resumes_meaningful_stage_and_intro_history_is_private_and_owner_scoped(): void
    {
        [$owner, $ownerSecretary] = $this->secretaryUser('History secretary');
        [$other] = $this->secretaryUser('Other history secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($owner, 'initial_story_complete');
        $tutorialId = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialId,
        ])->assertOk();

        $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->assertJsonPath('data.stage', 'escape_pending');
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonMissingPath('data.0.snapshot')
            ->assertJsonMissingPath('data.0.private_seed');
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.encounter_name', 'ジャイアントラット')
            ->assertJsonStructure(['data' => ['actions']])
            ->assertJsonMissingPath('data.snapshot');
        $battle = UndergroundBattle::query()->where('request_id', $tutorialId)->sole();
        $battle->log()->update(['expires_at' => Carbon::now()->subSecond()]);
        $this->artisan('underground:prune-battle-logs')->assertSuccessful();
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonPath('data.0.detail_available', false)
            ->assertJsonPath('data.0.actions', null);
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.detail_available', false)
            ->assertJsonPath('data.detail_message', '詳細ログは保存期間を過ぎました。')
            ->assertJsonPath('data.actions', null);
        $this->actingAs($other)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertNotFound();
        $ownerSecretary->delete();
        $this->assertSame(0, UndergroundProfile::query()->count());
        $this->assertSame(0, UndergroundBattle::query()->count());
        $this->assertSame(0, UndergroundIntroRequest::query()->count());
    }

    /** @return array{User, Secretary} */
    private function secretaryUser(string $name): array
    {
        $user = User::factory()->create();
        $secretary = app(SecretaryService::class)->ensureForUser($user);
        $secretary->update(['name' => $name, 'named_at' => Carbon::now()]);

        return [$user, $secretary];
    }

    private function reachShopkeeperNaming(User $user): void
    {
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'initial_story_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'escape_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'shopkeeper_encounter_complete')
            ->assertOk()->assertJsonPath('data.stage', 'shopkeeper_naming');
    }

    private function advance(User $user, string $action): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => (string) Str::uuid(),
            'action' => $action,
        ]);
    }

    private function tutorialBattle(UndergroundProfile $profile): UndergroundBattle
    {
        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-underground-intro-alpha-v2',
            'activity_type' => UndergroundBattle::ACTIVITY_TUTORIAL,
            'activity_key' => 'first_descent_tutorial',
            'encounter_key' => 'tutorial_giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 5,
            'shard_delta' => 0,
            'combat_level_before' => 1,
            'combat_level_after' => 1,
            'combat_xp_before' => 0,
            'combat_xp_after' => 5,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => 1,
            'snapshot' => [],
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
    }
}
