<?php

namespace Tests\Feature;

use App\Application\MessageBoardService;
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
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class MessageBoardApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_can_read_board_with_private_viewer_safe_cache_headers(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->ownerAndNation($world, '閲覧島');

        $response = $this->getJson("/api/v1/nations/{$nation->id}/message-board")
            ->assertOk()
            ->assertJsonPath('data.board.name', '閲覧島')
            ->assertJsonPath('data.entries', [])
            ->assertJsonPath('data.viewer.authenticated', false)
            ->assertJsonPath('data.viewer.can_post', false)
            ->assertJsonPath('data.contract.latest_limit', 16)
            ->assertHeader('Vary', 'Cookie');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_owner_other_nation_and_tourist_posts_keep_snapshot_author_types(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $world = $this->lightweightWorld();
        [$owner, $target] = $this->ownerAndNation($world, '投稿先島');
        [$other, $otherNation] = $this->ownerAndNation($world, '他島');
        $tourist = $this->user('visitor-raw-discord', '公開してはいけないOAuth名');

        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '島主です'])
            ->assertCreated()->assertJsonPath('data.entries.0.author.type', 'owner');
        Carbon::setTestNow(now()->addSeconds(10));
        $this->actingAs($other)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '他島です'])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.author.type', 'other_nation')
            ->assertJsonPath('data.entries.0.author.nation.nation_number', $otherNation->nation_number)
            ->assertJsonMissing(['visitor_code']);
        Carbon::setTestNow(now()->addSeconds(10));
        $touristResponse = $this->actingAs($tourist)
            ->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '観光客です'])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.author.type', 'visitor')
            ->assertJsonPath('data.entries.0.author.label', '観光客');

        $visitorCode = $tourist->fresh()->visitor_code;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', (string) $visitorCode);
        $touristResponse->assertJsonPath('data.entries.0.author.display_name', "観光客(ID:{$visitorCode})");
        foreach (['visitor-raw-discord', '公開してはいけないOAuth名'] as $privateIdentity) {
            $this->assertStringNotContainsString($privateIdentity, $touristResponse->getContent());
        }

        $lateNation = $this->nation($world, '後発島');
        NationMembership::query()->create([
            'user_id' => $tourist->id,
            'world_id' => $world->id,
            'nation_id' => $lateNation->id,
            'role' => 'owner',
        ]);
        $past = $this->actingAs($tourist)->getJson("/api/v1/nations/{$target->id}/message-board")
            ->assertOk()->json('data.entries.0');
        $this->assertSame('visitor', $past['author']['type']);
        $this->assertSame($visitorCode, $past['author']['visitor_code']);
    }

    public function test_google_tourist_response_exposes_no_raw_provider_email_or_display_identity(): void
    {
        $world = $this->lightweightWorld();
        [, $target] = $this->ownerAndNation($world, 'Google観光先');
        $tourist = User::factory()->create(['display_name' => 'private-email@example.test']);
        AuthIdentity::query()->create([
            'user_id' => $tourist->id,
            'provider' => 'google',
            'provider_user_id' => 'raw-google-provider-id-987654321',
            'display_name' => 'private-google-display-name',
        ]);

        $response = $this->actingAs($tourist)
            ->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => 'Google観光客'])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.author.type', 'visitor');

        $visitorCode = $tourist->fresh()->visitor_code;
        $response->assertJsonPath('data.entries.0.author.display_name', "観光客(ID:{$visitorCode})");
        foreach ([
            'raw-google-provider-id-987654321',
            'private-email@example.test',
            'private-google-display-name',
        ] as $privateIdentity) {
            $this->assertStringNotContainsString($privateIdentity, $response->getContent());
        }
    }

    public function test_unicode_length_boundaries_empty_and_plain_text_are_server_authoritative(): void
    {
        Carbon::setTestNow('2026-08-11 11:00:00');
        $world = $this->lightweightWorld();
        [, $target] = $this->ownerAndNation($world, '文字数島');
        $emptyUser = $this->user('empty-user');
        $longUser = $this->user('long-user');
        $validUser = $this->user('valid-user');

        $this->actingAs($emptyUser)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($longUser)->postJson("/api/v1/nations/{$target->id}/message-board", [
            'body' => str_repeat('あ', 140).'😀',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $plainText = '<script>alert(1)</script> '.str_repeat('日', 113).'😀';
        $this->assertSame(140, mb_strlen($plainText, 'UTF-8'));
        $response = $this->actingAs($validUser)
            ->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => $plainText])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.body', $plainText);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $this->assertDatabaseCount('island_messages', 1);
    }

    public function test_timeline_is_newest_first_stable_and_limited_after_placeholder_projection(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $target] = $this->ownerAndNation($world, '時系列島');
        [$sender, $senderNation] = $this->ownerAndNation($world, '送信島');
        $sameTime = Carbon::parse('2026-08-11 12:00:00');
        $ids = [];
        for ($index = 0; $index < 17; $index++) {
            $message = $index === 15
                ? $this->message($world, $target, $sender, '秘密本文', $sameTime, $senderNation)
                : $this->message($world, $target, $owner, "公開{$index}", $sameTime, null, $target);
            $ids[] = $message->public_id;
        }

        $response = $this->getJson("/api/v1/nations/{$target->id}/message-board")
            ->assertOk()->assertJsonCount(16, 'data.entries');
        $entries = $response->json('data.entries');
        $this->assertSame(array_reverse(array_slice($ids, 1)), array_column($entries, 'key'));
        $placeholder = collect($entries)->firstWhere('kind', 'secret_placeholder');
        $this->assertSame('--秘密通信あり--', $placeholder['text']);
        $this->assertArrayNotHasKey('body', $placeholder);
        $this->assertArrayNotHasKey('counterpart', $placeholder);
        $this->assertArrayNotHasKey('direction', $placeholder);
        $this->assertStringNotContainsString('秘密本文', $response->getContent());
        $this->assertStringNotContainsString($senderNation->name, $response->getContent());
    }

    public function test_target_retention_keeps_100_and_deletes_oldest_on_successful_insert(): void
    {
        Carbon::setTestNow('2026-08-11 13:00:00');
        $world = $this->lightweightWorld();
        [$owner, $target] = $this->ownerAndNation($world, '保持島');
        $oldest = null;
        for ($index = 0; $index < 100; $index++) {
            $message = $this->message(
                $world,
                $target,
                $owner,
                "保持{$index}",
                now()->subSeconds(100 - $index),
                null,
                $target,
            );
            $oldest ??= $message;
        }

        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '101件目'])
            ->assertCreated();

        $this->assertSame(100, IslandMessage::query()->where('target_nation_id', $target->id)->count());
        $this->assertDatabaseMissing('island_messages', ['id' => $oldest->id]);
        $this->assertDatabaseHas('island_messages', ['body' => '101件目']);
    }

    public function test_failed_validation_does_not_advance_cooldown_and_board_switch_cannot_bypass_it(): void
    {
        Carbon::setTestNow('2026-08-11 14:00:00');
        $world = $this->lightweightWorld();
        [, $first] = $this->ownerAndNation($world, '第一島');
        [, $second] = $this->ownerAndNation($world, '第二島');
        $tourist = $this->user('cooldown-tourist');

        $this->actingAs($tourist)->postJson("/api/v1/nations/{$first->id}/message-board", [
            'body' => str_repeat('a', 141),
        ])->assertUnprocessable();
        $this->actingAs($tourist)->postJson("/api/v1/nations/{$first->id}/message-board", ['body' => '成功'])
            ->assertCreated();
        $this->actingAs($tourist)->postJson("/api/v1/nations/{$second->id}/message-board", ['body' => '回避'])
            ->assertStatus(429)->assertHeader('Retry-After', '10');
        $this->assertSame(1, IslandMessage::query()->where('author_user_id', $tourist->id)->count());

        Carbon::setTestNow(now()->addSeconds(10));
        $this->actingAs($tourist)->postJson("/api/v1/nations/{$second->id}/message-board", ['body' => '10秒後'])
            ->assertCreated();
        $this->assertSame(2, IslandMessage::query()->where('author_user_id', $tourist->id)->count());
    }

    public function test_owner_from_another_world_cannot_be_projected_as_a_tourist(): void
    {
        $world = $this->lightweightWorld();
        [$owner] = $this->ownerAndNation($world, '第一World島');
        $otherWorld = World::query()->create([
            'key' => 'message-board-other-world',
            'name' => 'Message Board Other World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 1,
        ]);
        [, $target] = $this->ownerAndNation($otherWorld, '第二World島');

        $this->actingAs($owner)->getJson("/api/v1/nations/{$target->id}/message-board")
            ->assertOk()
            ->assertJsonPath('data.viewer.can_post', false)
            ->assertJsonPath('data.viewer.author_type', null);
        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/message-board", ['body' => '越境'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_nation');

        $this->assertSame(0, IslandMessage::query()->count());
        $this->assertNull($owner->fresh()->message_board_last_posted_at);
        $this->assertNull($owner->fresh()->visitor_code);
    }

    public function test_player_edit_and_delete_routes_do_not_exist(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $target] = $this->ownerAndNation($world, '編集不可島');
        $message = $this->message($world, $target, $owner, '固定本文', now(), null, $target);

        foreach (['patchJson', 'deleteJson'] as $method) {
            try {
                $this->actingAs($owner)->{$method}(
                    "/api/v1/nations/{$target->id}/message-board/{$message->public_id}",
                    ['body' => '変更'],
                )->assertStatus(405);
            } catch (MethodNotAllowedHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseHas('island_messages', ['id' => $message->id, 'body' => '固定本文']);
    }

    public function test_timeline_eager_loading_has_constant_query_count(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $target] = $this->ownerAndNation($world, 'Query島');
        [, $other] = $this->ownerAndNation($world, 'Query他島');
        for ($index = 0; $index < 16; $index++) {
            $this->message($world, $target, $owner, "query{$index}", now()->addSeconds($index), null, $other);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MessageBoardService::class)->timeline($target, $owner);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(7, count($queries));
    }

    /** @return array{User, Nation} */
    private function ownerAndNation(World $world, string $name, int $money = 500): array
    {
        $user = $this->user('identity-'.Str::slug($name).'-'.Str::random(5));
        $nation = $this->nation($world, $name, $money);
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'role' => 'owner',
        ]);

        return [$user, $nation];
    }

    private function user(string $providerUserId, string $displayName = '内部表示名'): User
    {
        $user = User::factory()->create(['display_name' => $displayName]);
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => $providerUserId,
            'display_name' => $displayName,
        ]);

        return $user;
    }

    private function nation(World $world, string $name, int $money = 500): Nation
    {
        return Nation::query()->create([
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
    }

    private function message(
        World $world,
        Nation $target,
        User $author,
        string $body,
        Carbon $createdAt,
        ?Nation $secretSender = null,
        ?Nation $authorNation = null,
    ): IslandMessage {
        $message = new IslandMessage;
        $message->timestamps = false;
        $message->forceFill([
            'public_id' => (string) Str::uuid(),
            'world_id' => $world->id,
            'target_nation_id' => $target->id,
            'author_user_id' => $author->id,
            'author_kind' => $secretSender !== null || $authorNation !== null ? 'nation' : 'visitor',
            'author_nation_id' => $secretSender?->id ?? $authorNation?->id,
            'secret_sender_nation_id' => $secretSender?->id,
            'message_type' => $secretSender === null ? 'public' : 'secret',
            'body' => $body,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $message;
    }
}
