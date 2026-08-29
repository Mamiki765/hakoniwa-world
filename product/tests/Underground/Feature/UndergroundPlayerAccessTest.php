<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryService;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $this->advance($user, 'shop_explanation_complete')->assertJsonPath('data.stage', 'underground_open');
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.shopkeeper_name', 'ダミー店員')
            ->assertJsonPath('data.combat_level', 1)
            ->assertJsonPath('data.combat_xp', 5);
        $requestCount = UndergroundIntroRequest::query()->count();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stage', 'underground_open');
        $this->assertSame($requestCount, UndergroundIntroRequest::query()->count());
        $this->assertSame(1, UndergroundBattle::query()->count());
    }

    public function test_exact_dummy_name_runs_one_logged_scripted_loss_without_normal_penalties(): void
    {
        [$user] = $this->secretaryUser('Special secretary');
        $this->reachShopkeeperNaming($user);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shopkeeper/name', [
            'request_id' => (string) Str::uuid(),
            'name' => ' ダミー ',
        ])->assertOk()->assertJsonPath('data.stage', 'special_loss_pending');

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
            ->assertJsonPath('data.battle.result', 'defeat')
            ->assertJsonPath('data.battle.xp_awarded', 0)
            ->assertJsonPath('data.battle.shard_delta', 0);
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

        $this->advance($user, 'special_loss_aftermath_complete')
            ->assertJsonPath('data.stage', 'shop_explanation');
        $this->advance($user, 'shop_explanation_complete')
            ->assertJsonPath('data.stage', 'underground_open');
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
}
