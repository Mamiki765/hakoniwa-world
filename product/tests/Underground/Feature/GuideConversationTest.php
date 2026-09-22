<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryService;
use App\Application\Underground\UndergroundProfileService;
use App\Models\AuthIdentity;
use App\Models\GuideConversationTopic;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GuideConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_editor_uses_fixed_dialogue_fields_code_defined_unlocks_and_fail_closed_authorization(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'guide-admin']);
        $admin = $this->identityUser('guide-admin');
        $nonAdmin = User::factory()->create();

        $this->getJson('/api/v1/admin/guide-conversation-topics')->assertForbidden();
        $this->actingAs($nonAdmin)->getJson('/api/v1/admin/guide-conversation-topics')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.can_manage_guide_topics', true);

        $this->actingAs($admin)->getJson('/api/v1/admin/guide-conversation-topics')
            ->assertOk()
            ->assertJsonFragment(['key' => 'always'])
            ->assertJsonFragment(['key' => 'trial_01_first_clear'])
            ->assertJsonFragment(['key' => 'trial_02_first_clear']);

        $invalid = $this->topicPayload();
        $invalid['choice_2'] = '片方だけ';
        $invalid['unlock_key'] = 'future_unregistered_unlock';
        $this->actingAs($admin)->postJson('/api/v1/admin/guide-conversation-topics', $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reply_2', 'unlock_key']);

        $created = $this->actingAs($admin)->postJson(
            '/api/v1/admin/guide-conversation-topics',
            $this->topicPayload(),
        )->assertCreated()
            ->assertJsonPath('data.initial_line', "今日はどうしました？\n少し休みますか？")
            ->assertJsonPath('data.choice_3', null)
            ->json('data');
        $this->assertSame($admin->id, $created['created_by_user_id']);
        $this->assertSame($admin->id, $created['updated_by_user_id']);

        $updated = $this->topicPayload();
        $updated['initial_line'] = '更新した台詞';
        $updated['enabled'] = false;
        $this->actingAs($admin)->patchJson(
            "/api/v1/admin/guide-conversation-topics/{$created['id']}",
            $updated,
        )->assertOk()
            ->assertJsonPath('data.initial_line', '更新した台詞')
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/guide-conversation-topics/{$created['id']}")
            ->assertNoContent();
        $this->assertDatabaseMissing('guide_conversation_topics', ['id' => $created['id']]);
    }

    public function test_player_conversation_is_random_from_eligible_topics_and_keeps_lifetime_totals_hidden(): void
    {
        $always = $this->topic('always', 'いつでも話せる話題');
        $trialOne = $this->topic('trial_01_first_clear', '試練1の話題');
        $trialTwo = $this->topic('trial_02_first_clear', '試練2の話題');
        [$user, $secretary, $profile] = $this->openPlayer('会話秘書');

        $this->postJson('/api/v1/me/underground/guide-conversation/start')->assertUnauthorized();
        $started = $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/start')
            ->assertOk()
            ->assertJsonPath('data.topic_id', $always->id)
            ->assertJsonPath('data.initial_line', 'いつでも話せる話題')
            ->assertJsonCount(2, 'data.choices');

        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/reply', [
            'topic_id' => $started->json('data.topic_id'),
            'position' => 3,
        ])->assertConflict()->assertJsonPath('code', 'guide_conversation_choice_unavailable');
        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/reply', [
            'topic_id' => $started->json('data.topic_id'),
            'position' => 2,
        ])->assertOk()->assertJsonPath('data.reply_line', '二つ目の返答');

        $punchLines = [
            '「いぎゃっ！？」', '「いったぁーい！？」', '「何すんのよぉっ！」', '「100万回やる気！？」',
            '「折れるじゃない！？　えっと……頭蓋骨が！」',
            '「すぐ治るからってやっていいことと悪いことあるんですからね！？」',
            '「んぎっ！？」', '「……っ！」', '「ひ、ひどいですっ！」', '「なんも企んでませんよぉ！？」',
            '「死なないと痛くないは別ですってぇ！」', '「私が何したって言うんですかあ！」',
        ];
        foreach (range(1, 2) as $_) {
            $line = $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/punch')
                ->assertOk()->json('data.punch_line');
            $this->assertContains($line, $punchLines);
        }
        $this->assertDatabaseHas('secretary_guide_conversation_totals', [
            'secretary_id' => $secretary->id,
            'topics_started' => 1,
            'normal_replies' => 1,
            'punch_count' => 2,
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground')
            ->assertOk()->assertJsonMissingPath('data.guide_conversation_totals');

        $always->update(['enabled' => false]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/start')
            ->assertConflict()->assertJsonPath('code', 'guide_conversation_unavailable');

        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/start')
            ->assertOk()->assertJsonPath('data.topic_id', $trialOne->id);

        $trialOne->update(['enabled' => false]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/start')
            ->assertConflict()->assertJsonPath('code', 'guide_conversation_unavailable');
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_02',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/guide-conversation/start')
            ->assertOk()->assertJsonPath('data.topic_id', $trialTwo->id);

        $this->assertDatabaseHas('secretary_guide_conversation_totals', [
            'secretary_id' => $secretary->id,
            'topics_started' => 3,
            'normal_replies' => 1,
            'punch_count' => 2,
        ]);
    }

    /** @return array{User, Secretary, UndergroundProfile} */
    private function openPlayer(string $name): array
    {
        $user = User::factory()->create();
        $secretary = app(SecretaryService::class)->ensureForUser($user);
        $secretary->update(['name' => $name, 'named_at' => Carbon::now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update([
            'underground_contract_completed_at' => Carbon::now(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'current_hp' => 492,
        ]);
        $tutorial = UndergroundBattle::query()->create([
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
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => 'リカ',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $tutorial->id,
        ]);

        return [$user, $secretary, $profile];
    }

    private function topic(string $unlockKey, string $initialLine): GuideConversationTopic
    {
        $owner = User::factory()->create();

        return GuideConversationTopic::query()->create([
            'initial_line' => $initialLine,
            'choice_1' => '一つ目',
            'reply_1' => '一つ目の返答',
            'choice_2' => '二つ目',
            'reply_2' => '二つ目の返答',
            'choice_3' => null,
            'reply_3' => null,
            'unlock_key' => $unlockKey,
            'enabled' => true,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function topicPayload(): array
    {
        return [
            'initial_line' => "今日はどうしました？\n少し休みますか？",
            'choice_1' => '話を聞く',
            'reply_1' => 'では、少しだけ。',
            'choice_2' => null,
            'reply_2' => null,
            'choice_3' => null,
            'reply_3' => null,
            'unlock_key' => 'always',
            'enabled' => true,
        ];
    }

    private function identityUser(string $providerUserId): User
    {
        $user = User::factory()->create(['display_name' => 'Guide admin']);
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => $providerUserId,
            'display_name' => 'Guide admin',
        ]);

        return $user;
    }
}
