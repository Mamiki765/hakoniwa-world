<?php

namespace Tests\Feature;

use App\Application\MessageBoardAuditRecorder;
use App\Application\MessageBoardService;
use App\Application\RulesetPublisher;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use App\Models\AuthIdentity;
use App\Models\IslandMessage;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class MessageBoardSecretTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_secret_debits_exactly_100_once_and_projects_one_record_by_board_and_viewer(): void
    {
        Carbon::setTestNow('2026-08-11 15:00:00');
        $world = $this->lightweightWorld();
        [$senderOwner, $sender] = $this->ownerAndNation($world, 'A島', 500);
        [$recipientOwner, $recipient] = $this->ownerAndNation($world, 'B島', 700);
        [$unrelatedOwner] = $this->ownerAndNation($world, 'C島', 900);
        $tourist = $this->user('tourist-secret');
        $secretBody = 'この本文は両島主だけが読めます';

        $senderResponse = $this->actingAs($senderOwner)
            ->postJson("/api/v1/nations/{$recipient->id}/message-board/secret", ['body' => $secretBody])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.kind', 'secret')
            ->assertJsonPath('data.entries.0.direction', 'incoming')
            ->assertJsonPath('data.entries.0.body', $secretBody)
            ->assertJsonPath('data.entries.0.counterpart.name', 'A島');
        $recordKey = $senderResponse->json('data.entries.0.key');

        $this->assertSame(400, $sender->fresh()->money);
        $this->assertSame(700, $recipient->fresh()->money);
        $this->assertSame(1, IslandMessage::query()->count());

        $recipientEntry = $this->actingAs($recipientOwner)
            ->getJson("/api/v1/nations/{$recipient->id}/message-board")
            ->assertOk()->json('data.entries.0');
        $this->assertSame($recordKey, $recipientEntry['key']);
        $this->assertSame('incoming', $recipientEntry['direction']);
        $this->assertSame($secretBody, $recipientEntry['body']);

        $outgoingEntry = $this->actingAs($senderOwner)
            ->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->json('data.entries.0');
        $this->assertSame($recordKey, $outgoingEntry['key']);
        $this->assertSame('outgoing', $outgoingEntry['direction']);
        $this->assertSame('B島', $outgoingEntry['counterpart']['name']);

        foreach ([$tourist, $unrelatedOwner] as $viewer) {
            $response = $this->actingAs($viewer)->getJson("/api/v1/nations/{$recipient->id}/message-board")
                ->assertOk()
                ->assertJsonPath('data.entries.0.key', $recordKey)
                ->assertJsonPath('data.entries.0.kind', 'secret_placeholder')
                ->assertJsonPath('data.entries.0.text', '--秘密通信あり--');
            $placeholder = json_encode($response->json('data.entries.0'), JSON_THROW_ON_ERROR);
            foreach ([$secretBody, 'A島', 'counterpart', 'direction', 'secret_sender', 'target_nation_id', 'cost_money'] as $secret) {
                $this->assertStringNotContainsString($secret, $placeholder);
            }
            foreach ([$secretBody, 'A島', 'counterpart', 'direction', 'secret_sender', 'target_nation_id', 'author_user_id'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->app['auth']->forgetGuards();
        $guestResponse = $this->getJson("/api/v1/nations/{$recipient->id}/message-board")
            ->assertOk()
            ->assertJsonPath('data.entries.0.key', $recordKey)
            ->assertJsonPath('data.entries.0.kind', 'secret_placeholder')
            ->assertJsonPath('data.entries.0.text', '--秘密通信あり--');
        $guestPlaceholder = json_encode($guestResponse->json('data.entries.0'), JSON_THROW_ON_ERROR);
        foreach ([$secretBody, 'A島', 'counterpart', 'direction', 'secret_sender', 'target_nation_id', 'cost_money'] as $secret) {
            $this->assertStringNotContainsString($secret, $guestPlaceholder);
        }
        foreach ([$secretBody, 'A島', 'counterpart', 'direction', 'secret_sender', 'target_nation_id', 'author_user_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $guestResponse->getContent());
        }

        $this->actingAs($unrelatedOwner)->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->assertJsonPath('data.entries', []);
        $this->actingAs($recipientOwner)->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->assertJsonPath('data.entries', []);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->assertJsonPath('data.entries', []);

        $audit = DB::table('audit_events')->where('event_type', 'message_board.secret_sent')->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->message);
        $this->assertSame($world->id, $audit->world_id);
        $this->assertSame($world->id, json_decode($audit->metadata, true, 512, JSON_THROW_ON_ERROR)['world_id']);
        $this->assertStringNotContainsString($secretBody, (string) $audit->metadata);
    }

    public function test_normal_and_secret_share_cooldown_without_partial_money_or_message_changes(): void
    {
        Carbon::setTestNow('2026-08-11 16:00:00');
        $world = $this->lightweightWorld();
        [$senderOwner, $sender] = $this->ownerAndNation($world, '送信島', 500);
        [, $target] = $this->ownerAndNation($world, '受信島', 500);

        $this->actingAs($senderOwner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '通常'])
            ->assertCreated();
        $this->actingAs($senderOwner)->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => '秘密'])
            ->assertStatus(429);
        $this->assertSame(500, $sender->fresh()->money);
        $this->assertSame(1, IslandMessage::query()->count());

        Carbon::setTestNow(now()->addSeconds(10));
        $this->actingAs($senderOwner)->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => '秘密成功'])
            ->assertCreated();
        $this->assertSame(400, $sender->fresh()->money);
        $this->actingAs($senderOwner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '通常回避'])
            ->assertStatus(429);
        $this->assertSame(2, IslandMessage::query()->count());
    }

    public function test_secret_rejects_tourist_self_cross_world_archived_and_insufficient_funds_without_cooldown(): void
    {
        Carbon::setTestNow('2026-08-11 17:00:00');
        $world = $this->lightweightWorld();
        [$owner, $sender] = $this->ownerAndNation($world, '送信元', 99);
        [, $target] = $this->ownerAndNation($world, '送信先', 500);
        $tourist = $this->user('secret-tourist-reject');

        $this->actingAs($tourist)->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => '不可'])
            ->assertForbidden();
        $this->actingAs($tourist)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '直後の通常投稿'])
            ->assertCreated();

        $this->actingAs($owner)->postJson("/api/v1/nations/{$sender->id}/message-board/secret", ['body' => 'self'])
            ->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => '不足'])
            ->assertUnprocessable()->assertJsonValidationErrors('money');
        $this->assertSame(99, $sender->fresh()->money);
        $this->assertNull($owner->fresh()->message_board_last_posted_at);

        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '不足後の通常'])
            ->assertCreated();

        $worldTwo = $this->secondWorld();
        [, $crossWorldTarget] = $this->ownerAndNation($worldTwo, '別World島', 500);
        Carbon::setTestNow(now()->addSeconds(10));
        $this->actingAs($owner)->postJson("/api/v1/nations/{$crossWorldTarget->id}/message-board/secret", ['body' => 'cross'])
            ->assertForbidden();

        $target->update(['state' => 'abandoned']);
        Carbon::setTestNow(now()->addSeconds(10));
        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => 'archive'])
            ->assertNotFound();
    }

    public function test_historical_world_rejects_public_and_secret_posts_without_any_mutation(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $sender] = $this->ownerAndNation($world, '履歴送信島', 500);
        [, $target] = $this->ownerAndNation($world, '履歴受信島', 500);
        $historical = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('roadmap-pr2-v1'),
        );
        $world->update(['ruleset_version_id' => $historical->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '通常拒否'])
            ->assertConflict()
            ->assertJsonPath('code', 'reset_required');
        $this->actingAs($owner)
            ->postJson("/api/v1/nations/{$target->id}/message-board/secret", ['body' => '秘密拒否'])
            ->assertConflict()
            ->assertJsonPath('code', 'reset_required');

        $this->assertSame(0, IslandMessage::query()->count());
        $this->assertSame(500, $sender->fresh()->money);
        $this->assertNull($owner->fresh()->message_board_last_posted_at);
        $this->assertSame(
            0,
            DB::table('audit_events')->where('event_type', 'message_board.secret_sent')->count(),
        );
    }

    public function test_transaction_rollback_restores_money_message_cooldown_and_audit(): void
    {
        Carbon::setTestNow('2026-08-11 18:00:00');
        $world = $this->lightweightWorld();
        [$owner, $sender] = $this->ownerAndNation($world, 'Rollback送信', 500);
        [, $target] = $this->ownerAndNation($world, 'Rollback受信', 500);
        $this->app->instance(MessageBoardAuditRecorder::class, new class extends MessageBoardAuditRecorder
        {
            public function secretSent(
                World $world,
                User $user,
                Nation $sender,
                Nation $target,
                IslandMessage $message,
                int $cost,
            ): void {
                throw new RuntimeException('forced audit failure');
            }
        });

        try {
            app(MessageBoardService::class)->postSecret($owner, $target, 'rollback-body');
            $this->fail('The forced failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced audit failure', $exception->getMessage());
        }

        $this->assertSame(500, $sender->fresh()->money);
        $this->assertNull($owner->fresh()->message_board_last_posted_at);
        $this->assertSame(0, IslandMessage::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'message_board.secret_sent')->count());
    }

    public function test_recipient_retention_deletion_removes_same_secret_from_sender_projection(): void
    {
        Carbon::setTestNow('2026-08-11 19:00:00');
        $world = $this->lightweightWorld();
        [$senderOwner, $sender] = $this->ownerAndNation($world, '保持送信', 500);
        [$recipientOwner, $recipient] = $this->ownerAndNation($world, '保持受信', 500);
        app(MessageBoardService::class)->postSecret($senderOwner, $recipient, '消える秘密');
        $secretId = IslandMessage::query()->where('message_type', 'secret')->value('id');

        for ($index = 0; $index < 100; $index++) {
            Carbon::setTestNow(now()->addSeconds(10));
            app(MessageBoardService::class)->postPublic($recipientOwner->fresh(), $recipient, "後続{$index}");
        }

        $this->assertDatabaseMissing('island_messages', ['id' => $secretId]);
        $this->assertSame(100, IslandMessage::query()->where('target_nation_id', $recipient->id)->count());
        $senderTimeline = app(MessageBoardService::class)->timeline($sender, $senderOwner);
        $this->assertSame([], $senderTimeline['entries']);
    }

    public function test_sender_public_latest16_is_calculated_after_outgoing_exclusion(): void
    {
        Carbon::setTestNow('2026-08-11 20:00:00');
        $world = $this->lightweightWorld();
        [$senderOwner, $sender] = $this->ownerAndNation($world, '16送信', 2_000);
        [, $recipient] = $this->ownerAndNation($world, '16受信', 500);
        for ($index = 0; $index < 16; $index++) {
            $this->insertPublic($world, $sender, $senderOwner, $sender, "公開{$index}", now()->subMinutes(30 - $index));
        }
        for ($index = 0; $index < 4; $index++) {
            Carbon::setTestNow(now()->addSeconds(10));
            app(MessageBoardService::class)->postSecret($senderOwner->fresh(), $recipient, "outgoing{$index}");
        }

        $public = $this->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->assertJsonCount(16, 'data.entries');
        $this->assertSame([], collect($public->json('data.entries'))->where('kind', 'secret_placeholder')->values()->all());
        $this->assertSame([], collect($public->json('data.entries'))->where('kind', 'secret')->values()->all());
        $owner = $this->actingAs($senderOwner)->getJson("/api/v1/nations/{$sender->id}/message-board")
            ->assertOk()->assertJsonCount(16, 'data.entries');
        $this->assertSame(4, collect($owner->json('data.entries'))->where('kind', 'secret')->count());
    }

    /** @return array{User, Nation} */
    private function ownerAndNation(World $world, string $name, int $money): array
    {
        $user = $this->user('identity-'.Str::random(12));
        $nation = Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => (int) Nation::query()->where('world_id', $world->id)->max('nation_number') + 1,
            'registered_turn' => 1,
            'name' => $name,
            'owner_name' => $name.'主',
            'profile_comment' => '',
            'money' => $money,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'role' => 'owner',
        ]);

        return [$user, $nation];
    }

    private function user(string $providerUserId): User
    {
        $user = User::factory()->create();
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => $providerUserId,
            'display_name' => 'private-name',
        ]);

        return $user;
    }

    private function secondWorld(): World
    {
        $source = World::query()->firstOrFail();

        return World::query()->create([
            'key' => 'second-message-world',
            'name' => 'Second Message World',
            'ruleset_version_id' => $source->ruleset_version_id,
            'current_turn' => 1,
        ]);
    }

    private function insertPublic(
        World $world,
        Nation $target,
        User $author,
        Nation $authorNation,
        string $body,
        Carbon $createdAt,
    ): void {
        $message = new IslandMessage;
        $message->timestamps = false;
        $message->forceFill([
            'public_id' => (string) Str::uuid(),
            'world_id' => $world->id,
            'target_nation_id' => $target->id,
            'author_user_id' => $author->id,
            'author_kind' => 'nation',
            'author_nation_id' => $authorNation->id,
            'secret_sender_nation_id' => null,
            'message_type' => 'public',
            'body' => $body,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }
}
