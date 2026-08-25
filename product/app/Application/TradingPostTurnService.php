<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\TradingPost\TradingPostRules;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\AuctionBid;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use DomainException;

final class TradingPostTurnService
{
    public function __construct(
        private readonly TurnEventRecorder $events,
        private readonly SecretaryItemCatalog $items,
    ) {}

    /** @return array<string, int> */
    public function execute(TurnContext $context): array
    {
        $rules = TradingPostRules::fromSettings($context->ruleset->settings);
        $metrics = [
            'sold' => 0,
            'auto_relisted' => 0,
            'unsold_returned' => 0,
            'npc_listed' => 0,
        ];
        $expired = AuctionListing::query()
            ->where('world_id', $context->world->id)
            ->where('status', AuctionListing::STATUS_ACTIVE)
            ->where('ends_turn', '<=', $context->targetTurn)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($expired as $listing) {
            if ($listing->bid_count > 0) {
                $this->settleSale($context, $rules, $listing);
                $metrics['sold']++;

                continue;
            }
            if ($listing->seller_type === 'nation'
                && $listing->auto_relist
                && ! $this->sellerReachedAbandonmentThreshold($context, $listing)) {
                $listing->update([
                    'started_turn' => $context->targetTurn,
                    'ends_turn' => $context->targetTurn + $listing->duration_turns,
                    'relist_count' => $listing->relist_count + 1,
                ]);
                $this->events->record($context, 'trading_post.auto_relisted', $listing, [
                    'nation_id' => $listing->seller_nation_id,
                    'auction_listing_id' => $listing->id,
                    'duration_turns' => $listing->duration_turns,
                    'started_turn' => $listing->started_turn,
                    'ends_turn' => $listing->ends_turn,
                    'relist_count' => $listing->relist_count,
                ], 'admin');
                $metrics['auto_relisted']++;

                continue;
            }
            $this->expireWithoutBid($context, $listing);
            if ($listing->seller_type === 'nation') {
                $metrics['unsold_returned']++;
            }
        }

        $metrics['npc_listed'] = $this->generateNpcListings($context, $rules);

        return $metrics;
    }

    private function settleSale(
        TurnContext $context,
        TradingPostRules $rules,
        AuctionListing $listing,
    ): void {
        $bid = AuctionBid::query()
            ->where('auction_listing_id', $listing->id)
            ->where('status', AuctionBid::STATUS_HIGHEST)
            ->lockForUpdate()
            ->sole();
        if ($listing->current_price !== $bid->amount
            || $listing->highest_bidder_nation_id !== $bid->bidder_nation_id) {
            throw new DomainException('交易場の最高入札escrow状態が不正です。');
        }
        $winner = Nation::query()->whereKey($bid->bidder_nation_id)->lockForUpdate()->firstOrFail();
        if ($winner->world_id !== $context->world->id || $winner->state === 'abandoned') {
            throw new DomainException('交易場の落札国状態が不正です。');
        }
        $delivery = $listing->product_type === 'resource'
            ? $this->deliverResource($listing, $winner)
            : $this->deliverItem($listing, $winner);

        $sellerProceeds = 0;
        $fee = 0;
        if ($listing->seller_type === 'nation') {
            $seller = Nation::query()->whereKey($listing->seller_nation_id)->lockForUpdate()->firstOrFail();
            $sellerProceeds = $this->sellerProceeds($bid->amount, $rules);
            $fee = $bid->amount - $sellerProceeds;
            if ($sellerProceeds > 0) {
                $seller->increment('money', $sellerProceeds);
            }
        }
        $bid->update(['status' => AuctionBid::STATUS_WON]);
        $listing->update([
            'status' => AuctionListing::STATUS_SOLD,
            'completed_turn' => $context->targetTurn,
        ]);
        $this->events->record($context, 'trading_post.sold', $listing, [
            'nation_id' => $winner->id,
            'auction_listing_id' => $listing->id,
            'seller_type' => $listing->seller_type,
            'seller_nation_id' => $listing->seller_nation_id,
            'winner_nation_id' => $winner->id,
            'winning_bid' => $bid->amount,
            'seller_proceeds' => $sellerProceeds,
            'trading_fee' => $fee,
            'product_type' => $listing->product_type,
            ...$delivery,
        ], 'admin');
    }

    /** @return array<string, int|string> */
    private function deliverResource(AuctionListing $listing, Nation $winner): array
    {
        $resource = ResourceDefinition::query()->whereKey($listing->resource_definition_id)->firstOrFail();
        $balance = NationResource::query()
            ->where('nation_id', $winner->id)
            ->where('resource_definition_id', $resource->id)
            ->lockForUpdate()
            ->first();
        $balance ??= NationResource::query()->create([
            'nation_id' => $winner->id,
            'resource_definition_id' => $resource->id,
            'amount' => 0,
        ]);
        $balance->increment('amount', $listing->quantity);

        return ['resource_key' => $resource->key, 'quantity' => $listing->quantity];
    }

    /** @return array<string, int|string> */
    private function deliverItem(AuctionListing $listing, Nation $winner): array
    {
        $membership = NationMembership::query()
            ->where('world_id', $winner->world_id)
            ->where('nation_id', $winner->id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->sole();
        $secretary = Secretary::query()->where('user_id', $membership->user_id)->lockForUpdate()->sole();
        if ($listing->seller_type === 'nation') {
            $item = SecretaryItemInstance::query()->whereKey($listing->secretary_item_instance_id)
                ->lockForUpdate()->firstOrFail();
            if (! $item->is_escrowed || $item->equipped_slot !== null
                || $item->item_key !== $listing->item_key || $item->level !== $listing->item_level) {
                throw new DomainException('交易場の商品アイテムescrow状態が不正です。');
            }
            $item->update([
                'secretary_id' => $secretary->id,
                'is_escrowed' => false,
                'equipped_slot' => null,
            ]);
        } else {
            $item = SecretaryItemInstance::query()->create([
                'secretary_id' => $secretary->id,
                'item_key' => $listing->item_key,
                'level' => $listing->item_level,
                'equipped_slot' => null,
                'is_escrowed' => false,
                'grant_key' => 'trading-post:npc:'.$listing->id,
                'obtained_at' => now(),
            ]);
        }

        return [
            'item_instance_id' => $item->id,
            'item_key' => $item->item_key,
            'item_level' => $item->level,
        ];
    }

    private function expireWithoutBid(TurnContext $context, AuctionListing $listing): void
    {
        $metadata = [
            'nation_id' => $listing->seller_nation_id,
            'auction_listing_id' => $listing->id,
            'seller_type' => $listing->seller_type,
            'product_type' => $listing->product_type,
            'quantity' => $listing->quantity,
            'item_instance_id' => $listing->secretary_item_instance_id,
        ];
        if ($listing->seller_type === 'nation') {
            $seller = Nation::query()->whereKey($listing->seller_nation_id)->lockForUpdate()->firstOrFail();
            if ($listing->product_type === 'resource') {
                $balance = NationResource::query()
                    ->where('nation_id', $seller->id)
                    ->where('resource_definition_id', $listing->resource_definition_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $balance->increment('amount', $listing->quantity);
            } else {
                $item = SecretaryItemInstance::query()->whereKey($listing->secretary_item_instance_id)
                    ->lockForUpdate()->firstOrFail();
                if (! $item->is_escrowed) {
                    throw new DomainException('交易場の商品アイテムescrow状態が不正です。');
                }
                $item->update(['is_escrowed' => false]);
            }
        }
        $listing->update([
            'status' => AuctionListing::STATUS_EXPIRED,
            'completed_turn' => $context->targetTurn,
        ]);
        $eventType = $listing->seller_type === 'nation'
            ? 'trading_post.unsold_returned'
            : 'trading_post.npc_expired';
        $this->events->record($context, $eventType, $listing, $metadata, 'admin');
    }

    private function generateNpcListings(TurnContext $context, TradingPostRules $rules): int
    {
        $counts = AuctionListing::query()
            ->where('world_id', $context->world->id)
            ->where('seller_type', 'hakoniwa_federation')
            ->where('status', AuctionListing::STATUS_ACTIVE)
            ->selectRaw('product_type, count(*) AS aggregate')
            ->groupBy('product_type')
            ->pluck('aggregate', 'product_type');
        $resourceCount = (int) ($counts['resource'] ?? 0);
        $itemCount = (int) ($counts['item'] ?? 0);
        $stream = $context->random->stream(TurnRandomStreamFactory::tradingPostNpc($rules->npcRandomStreamVersion));
        $created = 0;
        for ($attempt = 0; $attempt < $rules->npcAttemptsPerTurn; $attempt++) {
            if ($stream->integer(1, $rules->npcProbabilityDenominator) > $rules->npcProbabilityNumerator) {
                continue;
            }
            $resourceAvailable = $resourceCount < $rules->npcResourceLimit;
            $itemAvailable = $itemCount < $rules->npcItemLimit;
            if (! $resourceAvailable && ! $itemAvailable) {
                continue;
            }
            $productType = $resourceAvailable && $itemAvailable
                ? ($stream->integer(0, 1) === 0 ? 'resource' : 'item')
                : ($resourceAvailable ? 'resource' : 'item');
            $listing = $productType === 'resource'
                ? $this->createNpcResourceListing($context, $rules, $stream)
                : $this->createNpcItemListing($context, $rules, $stream);
            if ($productType === 'resource') {
                $resourceCount++;
            } else {
                $itemCount++;
            }
            $this->events->record($context, 'trading_post.npc_listed', $listing, [
                'auction_listing_id' => $listing->id,
                'seller_type' => 'hakoniwa_federation',
                'seller_name' => $rules->npcSellerName,
                'product_type' => $listing->product_type,
                'resource_definition_id' => $listing->resource_definition_id,
                'quantity' => $listing->quantity,
                'item_key' => $listing->item_key,
                'item_level' => $listing->item_level,
                'start_price' => $listing->start_price,
                'duration_turns' => $listing->duration_turns,
            ], 'admin');
            $created++;
        }

        return $created;
    }

    private function createNpcResourceListing(
        TurnContext $context,
        TradingPostRules $rules,
        DeterministicRandomStream $stream,
    ): AuctionListing {
        $resourceKey = $rules->npcResourceKeys[$stream->integer(0, count($rules->npcResourceKeys) - 1)];
        $resource = ResourceDefinition::query()->where('key', $resourceKey)->firstOrFail();
        $rate = $context->ruleset->settings['inventory_sale_rates'][$resourceKey] ?? null;
        if (! is_array($rate) || ! is_int($rate['inventory_units'] ?? null)
            || ! is_int($rate['money_units'] ?? null) || $rate['inventory_units'] < 1
            || $rate['money_units'] < 1) {
            throw new DomainException("交易場NPC資源{$resourceKey}の基準売価が不正です。");
        }
        $targetValue = $stream->integer($rules->npcResourceValueMinimum, $rules->npcResourceValueMaximum);
        $quantity = intdiv($targetValue * $rate['inventory_units'], $rate['money_units']);
        $baseValue = intdiv($quantity * $rate['money_units'], $rate['inventory_units']);
        $percent = $stream->integer(
            $rules->npcResourcePricePercentMinimum,
            $rules->npcResourcePricePercentMaximum,
        );
        $startPrice = max($baseValue, intdiv($baseValue * $percent, 100));

        return AuctionListing::query()->create([
            'world_id' => $context->world->id,
            'seller_type' => 'hakoniwa_federation',
            'seller_nation_id' => null,
            'product_type' => 'resource',
            'resource_definition_id' => $resource->id,
            'quantity' => $quantity,
            'start_price' => $startPrice,
            'duration_turns' => $rules->npcDurationTurns,
            'started_turn' => $context->targetTurn,
            'ends_turn' => $context->targetTurn + $rules->npcDurationTurns,
            'auto_relist' => false,
            'status' => AuctionListing::STATUS_ACTIVE,
        ]);
    }

    private function createNpcItemListing(
        TurnContext $context,
        TradingPostRules $rules,
        DeterministicRandomStream $stream,
    ): AuctionListing {
        $candidates = array_values(array_filter(
            $this->items->definitions(),
            static fn (array $definition): bool => $definition['rarity'] === $rules->npcItemRarity
                && $definition['npc_tradable'],
        ));
        if ($candidates === []) {
            throw new DomainException('交易場NPCアイテムの出品候補がありません。');
        }
        $definition = $candidates[$stream->integer(0, count($candidates) - 1)];
        $maximumLevel = min($rules->npcItemLevelMaximum, $definition['max_level']);
        $level = $stream->integer($rules->npcItemLevelMinimum, $maximumLevel);

        return AuctionListing::query()->create([
            'world_id' => $context->world->id,
            'seller_type' => 'hakoniwa_federation',
            'seller_nation_id' => null,
            'product_type' => 'item',
            'secretary_item_instance_id' => null,
            'item_key' => $definition['key'],
            'item_level' => $level,
            'start_price' => $level * $rules->npcItemPriceMoneyPerLevel,
            'duration_turns' => $rules->npcDurationTurns,
            'started_turn' => $context->targetTurn,
            'ends_turn' => $context->targetTurn + $rules->npcDurationTurns,
            'auto_relist' => false,
            'status' => AuctionListing::STATUS_ACTIVE,
        ]);
    }

    private function sellerProceeds(int $amount, TradingPostRules $rules): int
    {
        $whole = intdiv($amount, $rules->sellerProceedsDenominator);
        $remainder = $amount % $rules->sellerProceedsDenominator;

        return $whole * $rules->sellerProceedsNumerator
            + intdiv($remainder * $rules->sellerProceedsNumerator, $rules->sellerProceedsDenominator);
    }

    private function sellerReachedAbandonmentThreshold(
        TurnContext $context,
        AuctionListing $listing,
    ): bool {
        $threshold = $context->ruleset->settings['nation_lifecycle']['abandonment_idle_threshold'] ?? null;
        if (! is_int($threshold) || $threshold < 1) {
            throw new DomainException('交易場の自動再出品に必要な島放置判定が不正です。');
        }
        $seller = Nation::query()->whereKey($listing->seller_nation_id)->lockForUpdate()->firstOrFail();

        return $seller->idle_counter >= $threshold;
    }
}
