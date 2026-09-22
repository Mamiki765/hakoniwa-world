<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\Underground\UndergroundProfileService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\AuctionListing;
use App\Models\AuthIdentity;
use App\Models\CompensationGrant;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class AdminOperationsTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_admin_distribution_freezes_all_users_including_islandless_and_dormant_and_replays_once(): void
    {
        $world = $this->lightweightWorld();
        $admin = $this->admin();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '休眠配布島', '島主');
        $this->actingAs($owner)->postJson("/api/v1/nations/{$nation->id}/dormancy", ['days' => 7])->assertOk();
        $islandless = User::factory()->create();
        $endpoint = "/api/v1/admin/worlds/{$world->id}/operations/distribution";
        $input = ['target_kind' => 'all', 'reason' => '全員への配布', 'assets' => ['money' => 10, 'paradox' => 2]];
        $this->actingAs($islandless)->postJson($endpoint.'/preview', $input)->assertForbidden();
        $before = DB::table('audit_events')->count();
        $preview = $this->actingAs($admin)->postJson($endpoint.'/preview', $input)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$admin->id, $owner->id, $islandless->id], array_column($preview['recipients'], 'user_id'));
        $this->assertDatabaseCount('compensation_grants', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
        $laterUser = User::factory()->create();
        $this->postJson($endpoint.'/apply', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.recipient_count', 3);
        $this->postJson($endpoint.'/apply', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseCount('compensation_grants', 3);
        $this->assertDatabaseMissing('compensation_grants', ['recipient_user_id' => $laterUser->id]);
        $grant = CompensationGrant::query()->where('recipient_user_id', $islandless->id)->sole();
        $this->assertTrue($grant->expires_at->greaterThan(now()->addDays(364)));
        $this->actingAs($laterUser)->getJson('/api/v1/me/compensation-grants')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/me/compensation-grants/{$grant->id}/claim", ['request_id' => (string) Str::uuid()])->assertNotFound();

        // Explicit island numbers and secretary IDs resolve to recipient Users, with duplicate input collapsed.
        $number = $nation->nation_number;
        $selected = $this->actingAs($admin)->postJson($endpoint.'/preview', [
            ...$input, 'target_kind' => 'nation', 'selected_ids' => [$number], 'manual_ids' => "{$number}-{$number},{$number}",
        ])->assertOk()->json('data.recipients');
        $this->assertSame([$owner->id], array_column($selected, 'user_id'));
    }

    public function test_admin_cleanup_settles_only_related_auctions_atomically_and_keeps_user_progress_and_private_notes(): void
    {
        $world = $this->lightweightWorld();
        $admin = $this->admin();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $target = app(NationCreationService::class)->create($owner, $world, '整理対象島', '対象主');
        $peer = app(NationCreationService::class)->create($other, $world, '取引相手島', '取引主');
        $target->update(['money' => 1000]);
        $peer->update(['money' => 1000]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($owner->secretary()->firstOrFail());
        $profile->update(['shard_balance' => 321]);
        $oil = ResourceDefinition::query()->where('key', 'oil')->sole();
        NationResource::query()->where('nation_id', $target->id)->where('resource_definition_id', $oil->id)->update(['amount' => 1000]);
        $base = ['product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100, 'start_price' => 50, 'duration_turns' => 3, 'auto_relist' => true];
        $sold = $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/trading-post/listings", $base)->assertCreated()->json('data.id');
        $unsold = $this->postJson("/api/v1/nations/{$target->id}/trading-post/listings", $base)->assertCreated()->json('data.id');
        $this->actingAs($other)->postJson("/api/v1/nations/{$peer->id}/trading-post/listings/{$sold}/bids", ['amount' => 100])->assertOk();
        $item = $other->secretary()->firstOrFail()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::RING, 'level' => 1, 'grant_key' => 'admin-cleanup-item', 'obtained_at' => now(),
        ]);
        $purchase = $this->postJson("/api/v1/nations/{$peer->id}/trading-post/listings", [
            'product_type' => 'item', 'item_instance_id' => $item->id,
            'start_price' => 50, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/nations/{$target->id}/trading-post/listings/{$purchase}/bids", ['amount' => 100])->assertOk();
        $peerOilBefore = (int) NationResource::query()->where('nation_id', $peer->id)->where('resource_definition_id', $oil->id)->value('amount');
        $endpoint = "/api/v1/admin/worlds/{$world->id}/operations/abandonment";
        $preview = $this->actingAs($admin)->postJson($endpoint.'/preview', [
            'nation_id' => $target->id, 'public_reason' => '運営による海域整理', 'operator_note' => '公開しない連絡記録',
        ])->assertOk()->json('data');
        $this->assertCount(3, $preview['auctions']);
        $this->assertSame('active', $target->fresh()->state);

        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from "nation_capitals"')) {
                throw new RuntimeException('cleanup interrupted after auction settlement');
            }
        });
        try {
            $this->postJson($endpoint.'/apply', ['token' => $preview['token']])->assertServerError();
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertSame('active', $target->fresh()->state);
        $this->assertSame(AuctionListing::STATUS_ACTIVE, AuctionListing::query()->findOrFail($sold)->status);
        $this->postJson($endpoint.'/apply', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.settled_auction_count', 3);
        $this->postJson($endpoint.'/apply', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame('abandoned', $target->fresh()->state);
        $this->assertSame(990, (int) $peer->fresh()->money);
        $this->assertSame($peerOilBefore + 100, (int) NationResource::query()->where('nation_id', $peer->id)->where('resource_definition_id', $oil->id)->value('amount'));
        $this->assertSame($owner->secretary->id, $item->fresh()->secretary_id);
        $this->assertSame(321, $profile->fresh()->shard_balance);
        $this->assertSame(AuctionListing::STATUS_EXPIRED, AuctionListing::query()->findOrFail($unsold)->status);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.abandoned')->count());
        $news = $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")->assertOk()->getContent();
        $this->assertStringContainsString('運営による海域整理', json_decode($news, true)['data']['groups'][0]['events'][0]['message']);
        $this->assertStringNotContainsString('公開しない連絡記録', $news);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'nation.abandoned', 'actor_user_id' => $admin->id]);
    }

    private function admin(): User
    {
        config(['hakoniwa.admin.discord_user_id' => 'e-test-admin']);
        $user = User::factory()->create();
        AuthIdentity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => 'e-test-admin', 'display_name' => '管理人']);

        return $user;
    }
}
