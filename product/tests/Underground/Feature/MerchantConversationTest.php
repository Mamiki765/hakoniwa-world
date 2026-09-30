<?php

namespace Tests\Underground\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edits_plain_text_topics_and_players_only_read_enabled_answers(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'merchant-admin']);
        $admin = User::factory()->create();
        AuthIdentity::query()->create([
            'user_id' => $admin->id, 'provider' => 'discord',
            'provider_user_id' => 'merchant-admin', 'display_name' => '管理人',
        ]);
        $player = User::factory()->create();
        $adminPath = '/api/v1/admin/merchant-conversation-topics';
        $playerPath = '/api/v1/me/underground/merchant-conversation-topics';
        $payload = ['question' => '旅について', 'answer' => "楽しいところへ行こう。\n明日のことは明日の私が考えるよ☆", 'enabled' => true];

        $this->getJson($playerPath)->assertUnauthorized();
        $this->postJson($adminPath, $payload)->assertForbidden();
        $this->actingAs($player)->postJson($adminPath, $payload)->assertForbidden();
        $this->actingAs($admin)->postJson($adminPath, [...$payload, 'answer' => '<script>alert(1)</script>'])
            ->assertUnprocessable()->assertJsonValidationErrors(['answer']);
        $created = $this->actingAs($admin)->postJson($adminPath, $payload)
            ->assertCreated()->assertJsonPath('data.created_by_user_id', $admin->id)->json('data');
        $this->actingAs($player)->getJson($playerPath)->assertOk()
            ->assertJsonFragment(['id' => $created['id'], 'question' => $payload['question'], 'answer' => $payload['answer']])
            ->assertJsonMissingPath('data.topics.0.created_by_user_id');

        $this->actingAs($player)->patchJson($adminPath.'/'.$created['id'], [...$payload, 'enabled' => false])->assertForbidden();
        $this->actingAs($admin)->patchJson($adminPath.'/'.$created['id'], [...$payload, 'answer' => '更新した回答', 'enabled' => false])
            ->assertOk()->assertJsonPath('data.answer', '更新した回答')->assertJsonPath('data.enabled', false);
        $this->actingAs($player)->getJson($playerPath)->assertOk()->assertJsonMissing(['id' => $created['id']]);
        $this->actingAs($admin)->getJson($adminPath)->assertOk()->assertJsonFragment(['id' => $created['id'], 'enabled' => false]);
        $this->actingAs($player)->deleteJson($adminPath.'/'.$created['id'])->assertForbidden();
        $this->actingAs($admin)->deleteJson($adminPath.'/'.$created['id'])->assertNoContent();
        $this->assertDatabaseMissing('merchant_conversation_topics', ['id' => $created['id']]);
    }
}
