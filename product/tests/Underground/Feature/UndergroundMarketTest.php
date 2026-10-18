<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundMarketService;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\UndergroundMarketListing;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundMarketTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    public function test_purchase_moves_generated_equipment_once_and_replays_the_saved_result_after_admission_expiry(): void
    {
        [$seller, $sellerProfile] = $this->participant('出品する秘書', 100);
        [$buyer, $buyerProfile] = $this->participant('買う秘書', 100_000);
        [$rival, $rivalProfile] = $this->participant('別の買い手', 100_000);
        $this->fixed($buyerProfile, 'polished_steel_dagger');
        $this->fixed($rivalProfile, 'polished_steel_dagger');
        $item = $this->generated($sellerProfile, 15);
        $item->update(['is_protected' => true]);
        $price = app(UndergroundEquipmentCatalog::class)->sellPrice($item->generated_payload) + 7;
        $listing = $this->listing($seller, $item, $price);
        $this->assertTrue($item->fresh()->is_listed);

        $path = "/api/v1/me/underground/market/listings/{$listing['id']}/purchase";
        $this->actingAs($buyer);
        $admission = $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path])
            ->assertOk()->json('data');
        $payload = ['request_id' => $admission['request_id'], 'expected_price' => $price];
        $headers = ['X-Underground-Receipt' => $admission['token']];
        $result = $this->postJson($path, $payload, $headers)->assertOk()
            ->assertJsonPath('data.equipment_id', $item->id)
            ->assertJsonPath('data.paid', $price);
        $this->assertSame($buyerProfile->id, $item->fresh()->underground_profile_id);
        $this->assertSame($listing['id'], $item->fresh()->source_market_listing_id);
        $this->assertFalse($item->fresh()->is_listed);
        $this->assertNotNull($item->fresh()->grant_key);
        $this->assertSame(100_000 - $price, $buyerProfile->fresh()->shard_balance);
        $this->assertSame(100, $sellerProfile->fresh()->shard_balance);

        $deposit = app(UndergroundAlphaV1PlayerCatalog::class)->bankTransferUnit();
        $this->postJson('/api/v1/me/underground/bank/transfer', [
            'request_id' => (string) Str::uuid(), 'action' => 'deposit', 'amount' => $deposit,
        ])->assertOk();
        $this->travel(24)->hours();
        $this->postJson($path, $payload, $headers)->assertOk()->assertExactJson($result->json());
        $this->postJson($path, [...$payload, 'expected_price' => $price + 1], $headers)
            ->assertConflict()->assertJsonPath('code', 'request_conflict');
        $this->actingAs($rival)->postJson($path, [
            'request_id' => (string) Str::uuid(), 'expected_price' => $price,
        ])->assertConflict()->assertJsonPath('code', 'listing_unavailable');
        $this->assertSame(100_000 - $price - $deposit, $buyerProfile->fresh()->shard_balance);
        $this->assertSame(100_000, $rivalProfile->fresh()->shard_balance);
        $this->assertSame($buyerProfile->id, $item->fresh()->underground_profile_id);
        $this->assertDatabaseCount('underground_market_listings', 1);
        $this->assertDatabaseCount('underground_market_receipts', 2);
    }

    public function test_sold_snapshot_survives_npc_sale_and_proceeds_cannot_be_claimed_twice(): void
    {
        [$seller, $sellerProfile] = $this->participant('代金を受け取る秘書', 100);
        [$buyer, $buyerProfile] = $this->participant('品物を売却する秘書', 1_000);
        $item = $this->fixed($sellerProfile, 'iron_dagger');
        // The explicit 10% fee contract must floor a fractional seller payout.
        $listing = $this->listing($seller, $item, 101);
        $this->actingAs($buyer)->postJson("/api/v1/me/underground/market/listings/{$listing['id']}/purchase", [
            'request_id' => (string) Str::uuid(), 'expected_price' => 101,
        ])->assertOk();
        $this->postJson("/api/v1/me/underground/equipment/items/{$item->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $item->id]);
        $this->assertSame(1_000 - 101 + $listing['item']['sell_price'], $buyerProfile->fresh()->shard_balance);
        $mine = $this->actingAs($seller)->getJson('/api/v1/me/underground/market/mine')->assertOk()
            ->assertJsonPath('data.unclaimed_total', 90)
            ->assertJsonPath('data.sold.0.proceeds', 90)
            ->assertJsonPath('data.sold.0.fee', 11);
        $this->assertEquals($listing['item_snapshot'], $mine->json('data.sold.0.item_snapshot'));
        $this->assertSame(90, app(UndergroundMarketService::class)->attentionForProfile($sellerProfile)['unclaimed_total']);

        $payload = ['request_id' => (string) Str::uuid(), 'listing_ids' => [$listing['id']]];
        $claim = $this->postJson('/api/v1/me/underground/market/proceeds/claim', $payload)->assertOk()
            ->assertJsonPath('data.claimed', 90)->assertJsonPath('data.carried_shards', 190);
        $this->postJson('/api/v1/me/underground/market/proceeds/claim', $payload)
            ->assertOk()->assertExactJson($claim->json());
        $this->postJson('/api/v1/me/underground/market/proceeds/claim', [
            ...$payload, 'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.claimed', 0);
        $this->assertSame(190, $sellerProfile->fresh()->shard_balance);
        $this->assertSame(0, app(UndergroundMarketService::class)->attentionForProfile($sellerProfile)['unclaimed_count']);
        $claimedMine = $this->getJson('/api/v1/me/underground/market/mine')->assertOk()
            ->assertJsonPath('data.sold.0.claimed', true);
        $this->assertEquals($listing['item_snapshot'], $claimedMine->json('data.sold.0.item_snapshot'));
    }

    public function test_purchase_cap_counts_unequipped_equipment_and_resonance_but_rechecks_after_the_crystal_is_sold(): void
    {
        [$seller, $sellerProfile] = $this->participant('IL出品者', 100);
        [$buyer, $buyerProfile] = $this->participant('IL購入者', 100_000);
        $owned = $this->fixed($buyerProfile, 'steel_dagger');
        $this->fixed($buyerProfile, 'demon_sword_gram');
        $cap = app(UndergroundEquipmentCatalog::class)->definition($owned->definition_key)['item_level'];
        $item = $this->generated($sellerProfile, $cap + 5, 'resonance');
        $price = app(UndergroundEquipmentCatalog::class)->sellPrice($item->generated_payload);
        $listing = $this->listing($seller, $item, $price);
        $blocked = $this->actingAs($buyer)->getJson('/api/v1/me/underground/market')->assertOk()
            ->assertJsonPath('data.purchase_il_cap', $cap)
            ->assertJsonPath('data.listings.0.purchasable', false);
        $this->assertContains('il_cap_exceeded', $blocked->json('data.listings.0.blocked_reasons'));
        $crystal = $this->generated($buyerProfile, $cap + 10, 'resonance');
        $this->assertNull($owned->fresh()->equipped_slot);
        $this->assertNull($crystal->equipped_slot);
        $this->getJson('/api/v1/me/underground/market')->assertOk()
            ->assertJsonPath('data.purchase_il_cap', $cap + 10)
            ->assertJsonPath('data.listings.0.purchasable', true);

        $this->postJson("/api/v1/me/underground/equipment/items/{$crystal->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $balanceBefore = $buyerProfile->fresh()->shard_balance;
        $this->postJson("/api/v1/me/underground/market/listings/{$listing['id']}/purchase", [
            'request_id' => (string) Str::uuid(), 'expected_price' => $price,
        ])->assertUnprocessable()->assertJsonPath('code', 'il_cap_exceeded');
        $this->assertSame($balanceBefore, $buyerProfile->fresh()->shard_balance);
        $this->assertSame($sellerProfile->id, $item->fresh()->underground_profile_id);
        $this->assertTrue($item->fresh()->is_listed);
        $this->assertDatabaseHas('underground_market_listings', ['id' => $listing['id'], 'status' => 'active']);
    }

    public function test_legacy_expired_history_stays_released_without_marking_unshown_or_other_owners_listings_seen(): void
    {
        [$seller, $sellerProfile] = $this->participant('期限切れを見る秘書');
        [$other, $otherProfile] = $this->participant('別の出品者');
        $firstItem = $this->fixed($sellerProfile, 'iron_dagger');
        $secondItem = $this->fixed($sellerProfile, 'leather_armor');
        $first = $this->listing($seller, $firstItem, 100);
        $second = $this->listing($seller, $secondItem, 100);
        $foreignItem = $this->fixed($otherProfile, 'iron_dagger');
        $foreign = $this->listing($other, $foreignItem, 100);
        // These are supported terminal rows from the former v40 expiration path.
        UndergroundMarketListing::query()->whereIn('id', [$first['id'], $second['id'], $foreign['id']])->update([
            'status' => 'expired', 'expires_at' => now()->subDay(), 'expired_at' => now()->subDay(),
        ]);
        UndergroundOwnedEquipment::query()->whereIn('id', [$firstItem->id, $secondItem->id, $foreignItem->id])->update(['is_listed' => false]);

        $this->actingAs($seller)->getJson('/api/v1/me/underground/market/mine')->assertOk()
            ->assertJsonCount(2, 'data.expired')->assertJsonPath('data.active', []);
        $this->getJson('/api/v1/me/underground/market')->assertOk()->assertJsonPath('data.listings', []);
        $this->assertFalse($firstItem->fresh()->is_listed);
        $this->assertFalse($secondItem->fresh()->is_listed);
        $this->assertSame(2, app(UndergroundMarketService::class)->attentionForProfile($sellerProfile)['unseen_expired_count']);
        $this->assertNull(UndergroundMarketListing::query()->findOrFail($first['id'])->seen_by_seller_at);
        $this->assertNull(UndergroundMarketListing::query()->findOrFail($second['id'])->seen_by_seller_at);

        $this->postJson('/api/v1/me/underground/market/expired/seen', ['listing_ids' => [$first['id'], $foreign['id']]])
            ->assertOk()->assertJsonPath('data.market_attention.unseen_expired_count', 1);
        $this->postJson('/api/v1/me/underground/market/expired/seen', ['listing_ids' => [$first['id']]])
            ->assertOk()->assertJsonPath('data.market_attention.unseen_expired_count', 1);
        $this->assertNotNull(UndergroundMarketListing::query()->findOrFail($first['id'])->seen_by_seller_at);
        $this->assertNull(UndergroundMarketListing::query()->findOrFail($second['id'])->seen_by_seller_at);
        $this->assertNull(UndergroundMarketListing::query()->findOrFail($foreign['id'])->seen_by_seller_at);
        $this->assertSame(1, app(UndergroundMarketService::class)->attentionForProfile($otherProfile)['unseen_expired_count']);
    }

    public function test_active_listings_keep_their_slots_past_legacy_deadlines_until_purchase_or_cancellation(): void
    {
        [$seller, $sellerProfile] = $this->participant('長く出品する秘書');
        [$buyer, $buyerProfile] = $this->participant('後から買う秘書');
        $this->fixed($buyerProfile, 'polished_steel_dagger');
        $limit = config('hakoniwa.ruleset.underground_market.listing_limit');
        $listings = [];
        $items = [];
        for ($index = 0; $index < $limit; $index++) {
            $items[] = $item = $this->fixed($sellerProfile, 'iron_dagger');
            $listings[] = $listing = $this->listing($seller, $item, 100);
            $this->assertNull($listing['expires_at']);
        }
        // An active v40 row can still carry its original deadline until migration.
        UndergroundMarketListing::query()->whereKey($listings[0]['id'])->update(['expires_at' => now()->subDay()]);
        $this->travel(400)->days();

        $this->actingAs($seller)->getJson('/api/v1/me/underground/market/mine')->assertOk()
            ->assertJsonCount($limit, 'data.active')->assertJsonPath('data.expired', [])
            ->assertJsonPath('data.active.0.expires_at', null);
        $this->getJson('/api/v1/me/underground/market')->assertOk()->assertJsonCount($limit, 'data.listings');
        $this->assertSame(0, app(UndergroundMarketService::class)->attentionForProfile($sellerProfile)['unseen_expired_count']);
        foreach ($items as $item) {
            $this->assertTrue($item->fresh()->is_listed);
        }
        $extra = $this->fixed($sellerProfile, 'iron_dagger');
        $this->postJson('/api/v1/me/underground/market/listings', [
            'request_id' => (string) Str::uuid(), 'equipment_id' => $extra->id, 'price' => 100,
        ])->assertUnprocessable()->assertJsonPath('code', 'listing_limit');

        $purchase = ['request_id' => (string) Str::uuid(), 'expected_price' => 100];
        $path = '/api/v1/me/underground/market/listings/'.$listings[0]['id'].'/purchase';
        $result = $this->actingAs($buyer)->postJson($path, $purchase)->assertOk();
        $this->postJson($path, $purchase)->assertOk()->assertExactJson($result->json());
        $this->assertSame($buyerProfile->id, $items[0]->fresh()->underground_profile_id);
        $this->assertSame(4_900, $buyerProfile->fresh()->shard_balance);
        $this->actingAs($seller)->deleteJson('/api/v1/me/underground/market/listings/'.$listings[1]['id'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertFalse($items[1]->fresh()->is_listed);
        $this->assertSame(0, app(UndergroundMarketService::class)->attentionForProfile($sellerProfile)['unseen_expired_count']);
    }

    public function test_indefinite_migration_changes_only_active_deadlines_and_preserves_terminal_assets_and_receipts(): void
    {
        [$seller, $sellerProfile] = $this->participant('移行する出品者');
        [$buyer, $buyerProfile] = $this->participant('移行する購入者');
        $this->fixed($buyerProfile, 'polished_steel_dagger');
        $items = [];
        $listings = [];
        foreach (['active', 'sold', 'cancelled', 'expired'] as $status) {
            $items[$status] = $item = $this->fixed($sellerProfile, 'iron_dagger');
            $listings[$status] = $this->listing($seller, $item, 100);
        }
        $this->actingAs($buyer)->postJson('/api/v1/me/underground/market/listings/'.$listings['sold']['id'].'/purchase', [
            'request_id' => (string) Str::uuid(), 'expected_price' => 100,
        ])->assertOk();
        $this->actingAs($seller)->deleteJson('/api/v1/me/underground/market/listings/'.$listings['cancelled']['id'])->assertOk();
        UndergroundMarketListing::query()->whereKey($listings['expired']['id'])->update(['status' => 'expired', 'expired_at' => now()->subDay()]);
        $items['expired']->update(['is_listed' => false]);
        $deadline = now()->subDay();
        DB::table('underground_market_listings')->update(['expires_at' => $deadline]);
        DB::statement('ALTER TABLE underground_market_listings ALTER COLUMN expires_at SET NOT NULL');
        $receipt = DB::table('underground_market_receipts')
            ->whereRaw("result_snapshot->'listing'->>'id' = ?", [(string) $listings['active']['id']])->sole();
        $snapshot = json_decode($receipt->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $snapshot['listing']['expires_at'] = $deadline->toIso8601String();
        DB::table('underground_market_receipts')->where('id', $receipt->id)->update(['result_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $readRows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $terminalBefore = DB::table('underground_market_listings')->where('status', '<>', 'active')->orderBy('id')->get()->all();
        $assetsBefore = [$readRows('underground_profiles'), $readRows('underground_owned_equipment'), $readRows('underground_market_receipts')];

        $migration = require database_path('migrations/2026_10_18_020000_make_underground_market_listings_indefinite.php');
        DB::transaction(static function () use ($migration): void {
            $migration->up();
        });

        $this->assertNull(UndergroundMarketListing::query()->findOrFail($listings['active']['id'])->expires_at);
        $this->assertEquals($terminalBefore, DB::table('underground_market_listings')->where('status', '<>', 'active')->orderBy('id')->get()->all());
        $this->assertSame($assetsBefore, [$readRows('underground_profiles'), $readRows('underground_owned_equipment'), $readRows('underground_market_receipts')]);
        $this->assertTrue($items['active']->fresh()->is_listed);
        $this->assertSame($buyerProfile->id, $items['sold']->fresh()->underground_profile_id);
        $this->postJson('/api/v1/me/underground/market/listings', [
            'request_id' => $receipt->request_id, 'equipment_id' => $items['active']->id, 'price' => 100,
        ])->assertOk()->assertJsonPath('data.listing.expires_at', $deadline->toIso8601String());
        $this->getJson('/api/v1/me/underground/market/mine')->assertOk()
            ->assertJsonCount(1, 'data.active')->assertJsonCount(1, 'data.sold')->assertJsonCount(1, 'data.expired')
            ->assertJsonPath('data.active.0.expires_at', null)->assertJsonPath('data.unclaimed_total', 90);
    }

    /** @return array{User, UndergroundProfile} */
    private function participant(string $name, int $balance = 5_000): array
    {
        [$user, $secretary] = $this->secretaryUser($name);
        $profile = $this->openEquipmentProfile($secretary, $balance, 0);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile);

        return [$user, $profile];
    }

    private function fixed(UndergroundProfile $profile, string $key): UndergroundOwnedEquipment
    {
        return UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id, 'definition_key' => $key,
            'catalog_identity' => app(UndergroundEquipmentCatalog::class)->identity(),
            'instance_kind' => 'fixed', 'acquired_at' => now(),
        ]);
    }

    private function generated(UndergroundProfile $profile, int $level, string $category = 'armor'): UndergroundOwnedEquipment
    {
        $source = $this->tutorialBattle($profile);
        $generator = app(UndergroundRuntimeEquipmentGenerator::class);
        $payload = $generator->generate($level, 'bahamul', $category === 'resonance' ? 'unique' : 'common',
            $category, null, null, 741, 'market-test:'.$source->id);

        return UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id, 'definition_key' => $payload['key'],
            'catalog_identity' => app(UndergroundEquipmentCatalog::class)->identity(),
            'instance_kind' => 'generated', 'instance_identity' => $payload['instance_identity'],
            'generator_identity' => $payload['generator_identity'], 'generated_payload' => $payload,
            'quality_percent' => $generator->qualityPercent($payload),
            'source_battle_id' => $source->id, 'grant_key' => 'market-test:'.$source->id, 'acquired_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function listing(User $seller, UndergroundOwnedEquipment $item, int $price): array
    {
        return $this->actingAs($seller)->postJson('/api/v1/me/underground/market/listings', [
            'request_id' => (string) Str::uuid(), 'equipment_id' => $item->id, 'price' => $price,
        ])->assertOk()->json('data.listing');
    }
}
