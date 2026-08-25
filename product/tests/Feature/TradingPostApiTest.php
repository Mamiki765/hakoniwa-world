<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\NationCreationService;
use App\Application\TradingPostTurnService;
use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\AuctionBid;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class TradingPostApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_resource_listing_escrows_immediately_enforces_limits_and_returns_on_zero_bid_cancel(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $nation] = $this->ownerAndNation($world, '出品島');
        $oil = $this->resource('oil');
        $oilCapacity = app(NationCapacityResolver::class)->resolve($nation)->resources['oil'];
        $this->setResource($nation, $oil, $oilCapacity);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson("/api/v1/worlds/{$world->id}/trading-post")
            ->assertForbidden();
        $this->actingAs($outsider)->postJson($this->listingUrl($nation), [
            'product_type' => 'resource',
            'resource_definition_id' => $oil->id,
            'quantity' => 100,
            'start_price' => 50,
            'duration_turns' => 3,
            'auto_relist' => false,
        ])->assertForbidden();

        foreach ([2, 85] as $duration) {
            $this->actingAs($owner)->postJson($this->listingUrl($nation), [
                'product_type' => 'resource',
                'resource_definition_id' => $oil->id,
                'quantity' => 100,
                'start_price' => 50,
                'duration_turns' => $duration,
                'auto_relist' => false,
            ])->assertUnprocessable();
        }
        $this->assertSame($oilCapacity, $this->resourceAmount($nation, $oil));

        $listingIds = [];
        foreach (range(1, 3) as $number) {
            $listingIds[] = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
                'product_type' => 'resource',
                'resource_definition_id' => $oil->id,
                'quantity' => 100,
                'start_price' => 50 + $number,
                'duration_turns' => 3,
                'auto_relist' => $number === 3,
            ])->assertCreated()->json('data.id');
        }
        $this->assertSame($oilCapacity - 300, $this->resourceAmount($nation, $oil));
        $this->actingAs($owner)->getJson("/api/v1/worlds/{$world->id}/trading-post")
            ->assertOk()
            ->assertJsonPath('data.nation.state', 'active')
            ->assertJsonPath('data.permissions.can_mutate', true)
            ->assertJsonPath('data.my_listings.0.id', $listingIds[0])
            ->assertJsonPath('data.my_listings.0.product.resource_key', 'oil')
            ->assertJsonPath('data.my_listings.0.seller.name', '出品島')
            ->assertJsonPath('data.my_listings.0.start_price', 51)
            ->assertJsonPath('data.my_listings.0.current_price', null)
            ->assertJsonPath('data.my_listings.0.remaining_turns', 3)
            ->assertJsonPath('data.my_listings.0.ends_turn', $world->current_turn + 3);
        $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'resource',
            'resource_definition_id' => $oil->id,
            'quantity' => 100,
            'start_price' => 100,
            'duration_turns' => 3,
            'auto_relist' => false,
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson(
            $this->bidUrl($nation, (int) $listingIds[0]),
            ['amount' => 100],
        )->assertUnprocessable();
        $this->actingAs($owner)->deleteJson(
            $this->listingUrl($nation).'/'.$listingIds[0],
        )->assertOk()->assertJsonPath('data.status', AuctionListing::STATUS_CANCELLED);
        $this->assertSame($oilCapacity - 200, $this->resourceAmount($nation, $oil));

        // Simulate production refilling the liquid balance before the end-of-Turn capacity phase.
        $this->setResource($nation, $oil, $oilCapacity);
        app(CompleteTurnEngine::class)->execute(
            'enforce_capacities',
            $this->context($world, 2, [$nation->id], 'listed-resource-capacity'),
        );
        $this->assertSame($oilCapacity - 200, $this->resourceAmount($nation, $oil));
        $nationPayload = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}")->assertOk()->json('data');
        $oilPayload = collect($nationPayload['resources'])->firstWhere('key', 'oil');
        $this->assertSame(0, $oilPayload['remaining_capacity']);
        $this->assertTrue($oilPayload['is_at_capacity']);
        foreach (array_slice($listingIds, 1) as $listingId) {
            $this->actingAs($owner)->deleteJson($this->listingUrl($nation).'/'.$listingId)->assertOk();
        }
        $this->assertSame($oilCapacity, $this->resourceAmount($nation, $oil));

        $wheat = $this->resource('wheat');
        $foodCapacity = app(NationCapacityResolver::class)->resolve($nation)->foodTons;
        $foodTotal = (int) NationResource::query()
            ->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->sum('amount');
        $this->setResource(
            $nation,
            $wheat,
            $this->resourceAmount($nation, $wheat) + $foodCapacity - $foodTotal,
        );
        $foodListingId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'resource', 'resource_definition_id' => $wheat->id, 'quantity' => 100,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $foodCredit = app(CapacityBoundedAssetService::class)->creditFood($nation, $wheat, 100);
        $this->assertSame(0, $foodCredit->applied);
        $this->assertSame(100, $foodCredit->overflow);
        $this->actingAs($owner)->deleteJson($this->listingUrl($nation).'/'.$foodListingId)->assertOk();
        $this->assertSame($foodCapacity, (int) NationResource::query()
            ->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->sum('amount'));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.listed']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.cancelled']);
    }

    public function test_item_listing_blocks_equipment_and_duplicate_escrow_while_equipped_items_are_rejected(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $nation] = $this->ownerAndNation($world, '道具島');
        $secretary = $owner->secretary()->firstOrFail();
        $bow = $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)->sole();
        $ring = $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::RING,
            'level' => 3,
            'equipped_slot' => null,
            'grant_key' => 'test:trading-post:ring',
            'obtained_at' => now(),
        ]);

        $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'item', 'item_instance_id' => $bow->id,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertUnprocessable();
        $listingId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'item', 'item_instance_id' => $ring->id,
            'start_price' => 300, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->assertTrue($ring->fresh()->is_escrowed);

        $this->actingAs($owner)->getJson('/api/v1/me/secretary/equipment/2/options')
            ->assertOk()->assertJsonMissing(['id' => $ring->id]);
        $this->actingAs($owner)->putJson('/api/v1/me/secretary/equipment/2', [
            'item_id' => $ring->id,
            'expected_version' => $secretary->equipment_version,
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'item', 'item_instance_id' => $ring->id,
            'start_price' => 301, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertUnprocessable();

        $this->actingAs($owner)->deleteJson($this->listingUrl($nation).'/'.$listingId)->assertOk();
        $this->assertFalse($ring->fresh()->is_escrowed);
        $this->assertSame(SecretaryItemCatalog::RARITY_NOVICE, app(SecretaryItemCatalog::class)->definition('old_bow')['rarity']);
        $this->assertFalse(app(SecretaryItemCatalog::class)->definition('old_bow')['npc_tradable']);

        $bow->update(['equipped_slot' => null]);
        $bowListingId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'item', 'item_instance_id' => $bow->id,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        [$buyer, $buyerNation] = $this->ownerAndNation($world, '弓買島');
        $this->actingAs($buyer)->postJson($this->bidUrl($buyerNation, $bowListingId), ['amount' => 100])
            ->assertUnprocessable();
        $this->actingAs($owner)->deleteJson($this->listingUrl($nation).'/'.$bowListingId)->assertOk();
        $this->assertFalse($bow->fresh()->is_escrowed);

        foreach (range(1, 48) as $index) {
            $buyer->secretary()->firstOrFail()->itemInstances()->create([
                'item_key' => SecretaryItemCatalog::RING,
                'level' => 1,
                'equipped_slot' => null,
                'grant_key' => "test:trading-post:capacity:{$index}",
                'obtained_at' => now(),
            ]);
        }
        $firstCapacityListingId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'item', 'item_instance_id' => $ring->id,
            'start_price' => 300, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $otherWorld = World::query()->create([
            'key' => 'trading-post-capacity-world-two',
            'name' => '交易場容量World 2',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 1,
        ]);
        $otherBuyerNation = Nation::query()->create([
            'world_id' => $otherWorld->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => '別世界買島',
            'owner_name' => '別世界買島主',
            'profile_comment' => '',
            'money' => 900,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
        NationMembership::query()->create([
            'user_id' => $buyer->id,
            'world_id' => $otherWorld->id,
            'nation_id' => $otherBuyerNation->id,
            'role' => 'owner',
        ]);
        $reservedListing = AuctionListing::query()->create([
            'world_id' => $otherWorld->id,
            'seller_type' => 'hakoniwa_federation',
            'product_type' => 'item',
            'item_key' => SecretaryItemCatalog::RING,
            'item_level' => 1,
            'start_price' => 100,
            'current_price' => 100,
            'highest_bidder_nation_id' => $otherBuyerNation->id,
            'bid_count' => 1,
            'duration_turns' => 6,
            'started_turn' => 1,
            'ends_turn' => 7,
            'auto_relist' => false,
            'status' => AuctionListing::STATUS_ACTIVE,
        ]);
        AuctionBid::query()->create([
            'auction_listing_id' => $reservedListing->id,
            'bidder_nation_id' => $otherBuyerNation->id,
            'amount' => 100,
            'status' => AuctionBid::STATUS_HIGHEST,
            'placed_turn' => 1,
        ]);

        $this->actingAs($buyer)
            ->postJson($this->bidUrl($buyerNation, $firstCapacityListingId), ['amount' => 300])
            ->assertUnprocessable();
        $this->assertSame(1_000, $buyerNation->fresh()->money);
    }

    public function test_bids_escrow_money_refund_the_previous_highest_bid_and_prevent_cancellation(): void
    {
        $world = $this->lightweightWorld();
        [$seller, $sellerNation] = $this->ownerAndNation($world, '売り島', 1_000);
        [$first, $firstNation] = $this->ownerAndNation($world, '第一島', 500);
        [$second, $secondNation] = $this->ownerAndNation($world, '第二島', 500);
        $oil = $this->resource('oil');
        $this->setResource($sellerNation, $oil, 100);
        $listingId = $this->actingAs($seller)->postJson($this->listingUrl($sellerNation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');

        $firstNation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => $world->current_turn,
            'resume_at_turn' => null,
        ]);
        $dormantMarket = $this->actingAs($first)
            ->getJson("/api/v1/worlds/{$world->id}/trading-post")
            ->assertOk()
            ->assertJsonPath('data.nation.state', 'dormant')
            ->assertJsonPath('data.permissions.can_mutate', false)
            ->json('data.listings');
        $dormantListing = collect($dormantMarket)->firstWhere('id', $listingId);
        $this->assertIsArray($dormantListing);
        $this->assertFalse($dormantListing['can_bid']);
        $this->assertFalse($dormantListing['can_cancel']);
        $this->actingAs($first)->postJson($this->bidUrl($firstNation, $listingId), ['amount' => 100])
            ->assertUnprocessable();
        $firstNation->update([
            'state' => 'active',
            'state_reason' => null,
            'state_started_turn' => null,
            'resume_at_turn' => null,
        ]);

        $this->actingAs($first)->postJson($this->bidUrl($firstNation, $listingId), ['amount' => 99])
            ->assertUnprocessable();
        $this->actingAs($first)->postJson($this->bidUrl($firstNation, $listingId), ['amount' => 100])
            ->assertOk();
        $this->assertSame(400, $firstNation->fresh()->money);
        $this->actingAs($second)->postJson($this->bidUrl($secondNation, $listingId), ['amount' => 130])
            ->assertOk();
        $this->assertSame(500, $firstNation->fresh()->money);
        $this->assertSame(370, $secondNation->fresh()->money);
        $this->actingAs($second)->postJson($this->bidUrl($secondNation, $listingId), ['amount' => 150])
            ->assertOk();
        $this->assertSame(350, $secondNation->fresh()->money);
        $this->assertSame(2, AuctionBid::query()->where('status', AuctionBid::STATUS_REFUNDED)->count());
        $this->assertSame(1, AuctionBid::query()->where('status', AuctionBid::STATUS_HIGHEST)->count());

        $this->actingAs($seller)->deleteJson($this->listingUrl($sellerNation).'/'.$listingId)
            ->assertUnprocessable();

        [$capacityBidder, $capacityBidderNation] = $this->ownerAndNation($world, '上限入札島');
        [$capacityOutbidder, $capacityOutbidderNation] = $this->ownerAndNation($world, '上限更新島');
        $moneyCapacity = app(NationCapacityResolver::class)->resolve($capacityBidderNation)->money;
        $escrowAmount = $moneyCapacity - 999;
        $capacityBidderNation->update(['money' => $escrowAmount]);
        $capacityOutbidderNation->update(['money' => $moneyCapacity]);
        $this->setResource($sellerNation, $oil, 100);
        $capacityListingId = $this->actingAs($seller)->postJson($this->listingUrl($sellerNation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => $escrowAmount, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->actingAs($capacityBidder)
            ->postJson($this->bidUrl($capacityBidderNation, $capacityListingId), ['amount' => $escrowAmount])
            ->assertOk();
        $credit = app(CapacityBoundedAssetService::class)->creditMoney($capacityBidderNation, 1_000);
        $this->assertSame(999, $credit->applied);
        $this->assertSame(1, $credit->overflow);
        $this->assertSame(999, $capacityBidderNation->fresh()->money);
        $this->actingAs($capacityBidder)->getJson("/api/v1/nations/{$capacityBidderNation->id}")
            ->assertOk()
            ->assertJsonPath('data.money_remaining_capacity', 0)
            ->assertJsonPath('data.money_is_at_capacity', true);
        $this->actingAs($capacityOutbidder)->postJson(
            $this->bidUrl($capacityOutbidderNation, $capacityListingId),
            ['amount' => $escrowAmount + 1],
        )->assertOk();
        $this->assertSame($moneyCapacity, $capacityBidderNation->fresh()->money);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.bid_placed']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.outbid_refunded']);
    }

    public function test_settlement_is_rollback_safe_delivers_once_pays_ninety_percent_and_precedes_capacity_enforcement(): void
    {
        $world = $this->lightweightWorld();
        [$seller, $sellerNation] = $this->ownerAndNation($world, '精算売島', 1_000);
        [$buyer, $buyerNation] = $this->ownerAndNation($world, '精算買島', 1_000);
        $oil = $this->resource('oil');
        $this->setResource($sellerNation, $oil, 100);
        $this->setResource($buyerNation, $oil, 5_000);
        $ring = $seller->secretary()->firstOrFail()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::RING,
            'level' => 2,
            'equipped_slot' => null,
            'grant_key' => 'test:settlement:ring',
            'obtained_at' => now(),
        ]);
        $resourceListing = $this->actingAs($seller)->postJson($this->listingUrl($sellerNation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => 101, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $itemListing = $this->actingAs($seller)->postJson($this->listingUrl($sellerNation), [
            'product_type' => 'item', 'item_instance_id' => $ring->id,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->actingAs($buyer)->postJson($this->bidUrl($buyerNation, $resourceListing), ['amount' => 101])->assertOk();
        $this->actingAs($buyer)->postJson($this->bidUrl($buyerNation, $itemListing), ['amount' => 100])->assertOk();
        $this->assertSame(799, $buyerNation->fresh()->money);
        // A bidder may enter manual dormancy after bidding. Settlement still has
        // to apply the same resource overflow contract before returning control.
        $buyerNation->update([
            'state' => 'dormant',
            'state_reason' => 'manual',
            'state_started_turn' => $world->current_turn,
            'resume_at_turn' => $world->current_turn + 12,
        ]);

        $context = $this->context($world, 4, [$sellerNation->id, $buyerNation->id], 'settlement-retry');
        try {
            DB::transaction(function () use ($context): void {
                app(CompleteTurnEngine::class)->execute('enforce_capacities', $context);
                throw new RuntimeException('force rollback after market settlement');
            });
            $this->fail('Expected the injected rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback after market settlement', $exception->getMessage());
        }
        $this->assertSame(AuctionListing::STATUS_ACTIVE, AuctionListing::query()->findOrFail($resourceListing)->status);
        $this->assertSame(5_000, $this->resourceAmount($buyerNation, $oil));
        $this->assertSame(1_000, $sellerNation->fresh()->money);
        $this->assertTrue($ring->fresh()->is_escrowed);

        $retry = $this->context($world, 4, [$sellerNation->id, $buyerNation->id], 'settlement-retry', $context->run);
        app(CompleteTurnEngine::class)->execute('enforce_capacities', $retry);
        $this->assertSame(AuctionListing::STATUS_SOLD, AuctionListing::query()->findOrFail($resourceListing)->status);
        $this->assertSame(AuctionListing::STATUS_SOLD, AuctionListing::query()->findOrFail($itemListing)->status);
        $this->assertSame(5_000, $this->resourceAmount($buyerNation, $oil));
        $this->assertSame(999, $buyerNation->fresh()->money);
        $this->assertSame(1_180, $sellerNation->fresh()->money);
        $this->assertSame($buyer->secretary()->valueOrFail('id'), $ring->fresh()->secretary_id);
        $this->assertFalse($ring->fresh()->is_escrowed);
        $saleMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'trading_post.sold')
            ->where('subject_id', $resourceListing)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(90, $saleMetadata['seller_proceeds']);
        $this->assertSame(11, $saleMetadata['trading_fee']);
        $this->assertSame(0, $saleMetadata['stored_quantity']);
        $this->assertSame(100, $saleMetadata['overflow_sold_quantity']);
        $this->assertSame(0, $saleMetadata['overflow_discarded_quantity']);

        app(CompleteTurnEngine::class)->execute('enforce_capacities', $retry);
        $this->assertSame(5_000, $this->resourceAmount($buyerNation, $oil));
        $this->assertSame(1_180, $sellerNation->fresh()->money);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'trading_post.sold')->count());

        [$capacitySeller, $capacitySellerNation] = $this->ownerAndNation($world, '上限売島', 1_000);
        [$capacityBuyer, $capacityBuyerNation] = $this->ownerAndNation($world, '上限買島', 1_000);
        $this->setResource($capacitySellerNation, $oil, 100);
        $moneyCapacity = app(NationCapacityResolver::class)->resolve($capacitySellerNation)->money;
        $capacitySellerNation->update(['money' => $moneyCapacity - 10]);
        $capacityListing = $this->actingAs($capacitySeller)->postJson($this->listingUrl($capacitySellerNation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => 101, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->actingAs($capacityBuyer)
            ->postJson($this->bidUrl($capacityBuyerNation, $capacityListing), ['amount' => 101])
            ->assertOk();

        app(TradingPostTurnService::class)->execute($this->context(
            $world,
            4,
            [$capacitySellerNation->id, $capacityBuyerNation->id],
            'capacity-bounded-settlement',
        ));
        $this->assertSame($moneyCapacity, $capacitySellerNation->fresh()->money);
        $capacityMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'trading_post.sold')
            ->where('subject_id', $capacityListing)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(90, $capacityMetadata['seller_proceeds_requested']);
        $this->assertSame(10, $capacityMetadata['seller_proceeds']);
        $this->assertSame(80, $capacityMetadata['seller_proceeds_overflow']);
        $this->assertSame(11, $capacityMetadata['trading_fee']);
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'trading_post.sold')->count());

        $wheat = $this->resource('wheat');
        $this->setResource($capacitySellerNation, $wheat, 100);
        $foodCapacity = app(NationCapacityResolver::class)->resolve($capacityBuyerNation)->foodTons;
        $foodTotal = (int) NationResource::query()
            ->where('nation_id', $capacityBuyerNation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->sum('amount');
        $this->setResource(
            $capacityBuyerNation,
            $wheat,
            $this->resourceAmount($capacityBuyerNation, $wheat) + $foodCapacity - $foodTotal,
        );
        $foodListing = $this->actingAs($capacitySeller)->postJson($this->listingUrl($capacitySellerNation), [
            'product_type' => 'resource', 'resource_definition_id' => $wheat->id, 'quantity' => 100,
            'start_price' => 101, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $this->actingAs($capacityBuyer)
            ->postJson($this->bidUrl($capacityBuyerNation, $foodListing), ['amount' => 101])
            ->assertOk();

        app(TradingPostTurnService::class)->execute($this->context(
            $world,
            4,
            [$capacitySellerNation->id, $capacityBuyerNation->id],
            'food-capacity-bounded-settlement',
        ));
        $this->assertSame($foodCapacity, (int) NationResource::query()
            ->where('nation_id', $capacityBuyerNation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->sum('amount'));
        $this->assertSame(798, $capacityBuyerNation->fresh()->money);
        $foodMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'trading_post.sold')
            ->where('subject_id', $foodListing)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $foodMetadata['stored_quantity']);
        $this->assertSame(0, $foodMetadata['overflow_sold_quantity']);
        $this->assertSame(100, $foodMetadata['overflow_discarded_quantity']);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'resource.food_overflow_resolved',
            'nation_id' => $capacityBuyerNation->id,
        ]);
        $this->assertSame(4, DB::table('audit_events')->where('event_type', 'trading_post.sold')->count());
    }

    public function test_no_bid_expiration_relist_and_npc_generation_follow_the_v16_contract(): void
    {
        $world = $this->lightweightWorld();
        [$owner, $nation] = $this->ownerAndNation($world, '再出品島');
        $oil = $this->resource('oil');
        $this->setResource($nation, $oil, 300);
        $returnedId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => 100, 'duration_turns' => 3, 'auto_relist' => false,
        ])->assertCreated()->json('data.id');
        $relistedId = $this->actingAs($owner)->postJson($this->listingUrl($nation), [
            'product_type' => 'resource', 'resource_definition_id' => $oil->id, 'quantity' => 100,
            'start_price' => 120, 'duration_turns' => 3, 'auto_relist' => true,
        ])->assertCreated()->json('data.id');

        app(TradingPostTurnService::class)->execute($this->context($world, 4, [$nation->id], 'relist'));
        $this->assertSame(AuctionListing::STATUS_EXPIRED, AuctionListing::query()->findOrFail($returnedId)->status);
        $relisted = AuctionListing::query()->findOrFail($relistedId);
        $this->assertSame(AuctionListing::STATUS_ACTIVE, $relisted->status);
        $this->assertSame(4, $relisted->started_turn);
        $this->assertSame(7, $relisted->ends_turn);
        $this->assertSame(3, $relisted->duration_turns);
        $this->assertSame(1, $relisted->relist_count);
        $this->assertSame(200, $this->resourceAmount($nation, $oil));
        $nation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => $world->current_turn,
            'resume_at_turn' => null,
        ]);
        $dormantMarket = $this->actingAs($owner)
            ->getJson("/api/v1/worlds/{$world->id}/trading-post")
            ->assertOk()
            ->assertJsonPath('data.permissions.can_mutate', false)
            ->json('data.my_listings');
        $dormantListing = collect($dormantMarket)->firstWhere('id', $relistedId);
        $this->assertIsArray($dormantListing);
        $this->assertTrue($dormantListing['can_cancel']);
        $this->actingAs($owner)->deleteJson($this->listingUrl($nation).'/'.$relistedId)->assertOk();
        $this->assertSame(300, $this->resourceAmount($nation, $oil));
        $nation->update([
            'state' => 'active',
            'state_reason' => null,
            'state_started_turn' => null,
            'resume_at_turn' => null,
        ]);

        foreach (range(5, 80) as $turn) {
            app(TradingPostTurnService::class)->execute($this->context($world, $turn, [$nation->id], 'npc-'.$turn));
        }
        $activeNpc = AuctionListing::query()->where('seller_type', 'hakoniwa_federation')
            ->where('status', AuctionListing::STATUS_ACTIVE)->get();
        $this->assertLessThanOrEqual(3, $activeNpc->where('product_type', 'resource')->count());
        $this->assertLessThanOrEqual(2, $activeNpc->where('product_type', 'item')->count());
        $allNpc = AuctionListing::query()->where('seller_type', 'hakoniwa_federation')->get();
        $this->assertNotEmpty($allNpc);
        $this->assertGreaterThan(0, $allNpc->where('product_type', 'resource')->count());
        $this->assertGreaterThan(0, $allNpc->where('product_type', 'item')->count());
        foreach ($allNpc as $listing) {
            $this->assertSame(6, $listing->duration_turns);
            $this->assertFalse($listing->auto_relist);
            if ($listing->product_type === 'item') {
                $this->assertSame(SecretaryItemCatalog::RING, $listing->item_key);
                $this->assertNotSame(SecretaryItemCatalog::OLD_BOW, $listing->item_key);
                $this->assertGreaterThanOrEqual(1, $listing->item_level);
                $this->assertLessThanOrEqual(5, $listing->item_level);
                $this->assertSame($listing->item_level * 100, $listing->start_price);

                continue;
            }
            $resource = ResourceDefinition::query()->findOrFail($listing->resource_definition_id);
            $rate = $world->rulesetVersion()->firstOrFail()->settings['inventory_sale_rates'][$resource->key];
            $baseValue = intdiv($listing->quantity * $rate['money_units'], $rate['inventory_units']);
            $this->assertGreaterThanOrEqual(100, $baseValue);
            $this->assertLessThanOrEqual(1_000, $baseValue);
            $this->assertGreaterThanOrEqual($baseValue, $listing->start_price);
            $this->assertLessThanOrEqual(intdiv($baseValue * 130, 100), $listing->start_price);
        }
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.auto_relisted']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.unsold_returned']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'trading_post.npc_listed']);
    }

    /** @return array{User, Nation} */
    private function ownerAndNation(World $world, string $name, int $money = 1_000): array
    {
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, $name, $name.'主');
        $nation->update(['money' => $money, 'state' => 'active', 'idle_counter' => 0]);

        return [$owner, $nation->fresh()];
    }

    private function resource(string $key): ResourceDefinition
    {
        return ResourceDefinition::query()->where('key', $key)->sole();
    }

    private function setResource(Nation $nation, ResourceDefinition $resource, int $amount): void
    {
        NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)->update(['amount' => $amount]);
    }

    private function resourceAmount(Nation $nation, ResourceDefinition $resource): int
    {
        return (int) NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)->valueOrFail('amount');
    }

    /** @param list<int> $nationIds */
    private function context(
        World $world,
        int $targetTurn,
        array $nationIds,
        string $seedKey,
        ?TurnRun $run = null,
    ): TurnContext {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $seed = hash('sha256', $seedKey);
        $run ??= TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds($nationIds);

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    private function listingUrl(Nation $nation): string
    {
        return "/api/v1/nations/{$nation->id}/trading-post/listings";
    }

    private function bidUrl(Nation $nation, int $listingId): string
    {
        return $this->listingUrl($nation)."/{$listingId}/bids";
    }
}
