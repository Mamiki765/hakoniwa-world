<?php

namespace App\Application;

use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\TradingPost\TradingPostRules;
use App\Domain\World\WorldMutationLock;
use App\Models\AuctionBid;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TradingPostService
{
    public function __construct(
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly SecretaryItemCatalog $items,
        private readonly SecretaryItemGameplayContract $itemGameplay,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $user, World $world): array
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $world->id)
            ->where('role', 'owner')
            ->whereHas('nation', fn ($query) => $query->whereIn('state', ['active', 'dormant', 'recovery']))
            ->with('nation')
            ->first();
        if (! $membership instanceof NationMembership) {
            throw new AuthorizationException('交易場は自国を持つ島主だけが利用できます。');
        }
        $nation = $membership->nation;
        $canMutate = in_array($nation->state, ['active', 'recovery'], true);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $rules = TradingPostRules::fromSettings($ruleset->settings);
        $listings = AuctionListing::query()
            ->where('world_id', $world->id)
            ->where('status', AuctionListing::STATUS_ACTIVE)
            ->with(['sellerNation', 'highestBidderNation', 'resourceDefinition'])
            ->orderBy('ends_turn')
            ->orderBy('id')
            ->get();
        $viewerBidListingIds = $listings->isEmpty()
            ? []
            : array_fill_keys(
                AuctionBid::query()
                    ->whereIn('auction_listing_id', $listings->modelKeys())
                    ->where('bidder_nation_id', $nation->id)
                    ->pluck('auction_listing_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
                true,
            );

        $resources = ResourceDefinition::query()
            ->where('tradable', true)
            ->with(['nationBalances' => fn ($query) => $query->where('nation_id', $nation->id)])
            ->orderBy('sort_order')
            ->get()
            ->map(static function (ResourceDefinition $resource): array {
                $balance = $resource->nationBalances->first();

                return [
                    'id' => $resource->id,
                    'key' => $resource->key,
                    'name' => $resource->name,
                    'unit_label' => $resource->unit_label,
                    'amount' => $balance instanceof NationResource ? $balance->amount : 0,
                ];
            })->values()->all();
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $itemOptions = [];
        if ($secretary instanceof Secretary) {
            foreach ($secretary->itemInstances()->whereNull('equipped_slot')->where('is_escrowed', false)
                ->orderBy('obtained_at')->orderBy('id')->get() as $item) {
                $definition = $this->items->definition($item->item_key);
                if (! $definition['tradable']) {
                    continue;
                }
                $itemOptions[] = [
                    'id' => $item->id,
                    'key' => $item->item_key,
                    'name' => $definition['name'],
                    'level' => $item->level,
                    'rarity' => $definition['rarity'],
                    'rarity_label' => $definition['rarity_label'],
                    'effect_text' => $this->itemGameplay->effectText($ruleset->settings, $item->item_key, $item->level),
                ];
            }
        }

        return [
            'world' => ['id' => $world->id, 'current_turn' => $world->current_turn],
            'nation' => [
                'id' => $nation->id,
                'name' => $nation->name,
                'money' => $nation->money,
                'state' => $nation->state,
            ],
            'permissions' => ['can_mutate' => $canMutate],
            'listings' => $listings->map(
                fn (AuctionListing $listing): array => $this->presentListing(
                    $listing,
                    $nation,
                    $world->current_turn,
                    $rules,
                    $ruleset->settings,
                    $viewerBidListingIds,
                ),
            )->all(),
            'my_listings' => $listings->where('seller_nation_id', $nation->id)->map(
                fn (AuctionListing $listing): array => $this->presentListing(
                    $listing,
                    $nation,
                    $world->current_turn,
                    $rules,
                    $ruleset->settings,
                    $viewerBidListingIds,
                ),
            )->values()->all(),
            'sellable_resources' => $resources,
            'sellable_items' => $itemOptions,
            'contract' => [
                'active_listing_limit' => $rules->activeListingLimit,
                'minimum_duration_turns' => $rules->minimumDurationTurns,
                'maximum_duration_turns' => $rules->maximumDurationTurns,
                'minimum_increment_money' => $rules->minimumIncrementMoney,
                'money_unit_label' => '億円',
                'npc_seller_name' => $rules->npcSellerName,
            ],
        ];
    }

    public function listResource(
        User $user,
        Nation $nation,
        ResourceDefinition $resource,
        int $quantity,
        int $startPrice,
        int $durationTurns,
        bool $autoRelist,
    ): AuctionListing {
        if ($quantity < 1) {
            throw new DomainException('出品数量は1以上で指定してください。');
        }
        if (! $resource->tradable) {
            throw new DomainException('この資源は交易場へ出品できません。');
        }

        return $this->mutate($user, $nation, function (World $world, Nation $lockedNation, TradingPostRules $rules) use (
            $user, $resource, $quantity, $startPrice, $durationTurns, $autoRelist,
        ): AuctionListing {
            $this->assertListingInput($lockedNation, $rules, $startPrice, $durationTurns);
            $balance = NationResource::query()
                ->where('nation_id', $lockedNation->id)
                ->where('resource_definition_id', $resource->id)
                ->lockForUpdate()
                ->first();
            if (! $balance instanceof NationResource || $balance->amount < $quantity) {
                throw new DomainException('出品する資源が不足しています。');
            }
            $balance->decrement('amount', $quantity);
            $listing = AuctionListing::query()->create([
                'world_id' => $world->id,
                'seller_type' => 'nation',
                'seller_nation_id' => $lockedNation->id,
                'product_type' => 'resource',
                'resource_definition_id' => $resource->id,
                'quantity' => $quantity,
                'start_price' => $startPrice,
                'duration_turns' => $durationTurns,
                'started_turn' => $world->current_turn,
                'ends_turn' => $world->current_turn + $durationTurns,
                'auto_relist' => $autoRelist,
                'status' => AuctionListing::STATUS_ACTIVE,
            ]);
            $this->record($user, $world, $lockedNation, $listing, 'trading_post.listed', [
                'product_type' => 'resource', 'resource_key' => $resource->key,
                'quantity' => $quantity, 'start_price' => $startPrice,
                'duration_turns' => $durationTurns, 'auto_relist' => $autoRelist,
            ]);

            return $listing->fresh(['sellerNation', 'highestBidderNation', 'resourceDefinition']);
        });
    }

    public function listItem(
        User $user,
        Nation $nation,
        SecretaryItemInstance $item,
        int $startPrice,
        int $durationTurns,
        bool $autoRelist,
    ): AuctionListing {
        return $this->mutate($user, $nation, function (World $world, Nation $lockedNation, TradingPostRules $rules) use (
            $user, $item, $startPrice, $durationTurns, $autoRelist,
        ): AuctionListing {
            $this->assertListingInput($lockedNation, $rules, $startPrice, $durationTurns);
            $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedItem = SecretaryItemInstance::query()
                ->whereKey($item->id)
                ->where('secretary_id', $secretary->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedItem instanceof SecretaryItemInstance) {
                throw new DomainException('所有していないアイテムは出品できません。');
            }
            $definition = $this->items->definition($lockedItem->item_key);
            if (! $definition['tradable']) {
                throw new DomainException('このアイテムは交易場へ出品できません。');
            }
            if ($lockedItem->equipped_slot !== null) {
                throw new DomainException('装備中のアイテムは外してから出品してください。');
            }
            if ($lockedItem->is_escrowed) {
                throw new DomainException('このアイテムはすでに交易場へ出品中です。');
            }
            $lockedItem->update(['is_escrowed' => true]);
            $listing = AuctionListing::query()->create([
                'world_id' => $world->id,
                'seller_type' => 'nation',
                'seller_nation_id' => $lockedNation->id,
                'product_type' => 'item',
                'secretary_item_instance_id' => $lockedItem->id,
                'item_key' => $lockedItem->item_key,
                'item_level' => $lockedItem->level,
                'start_price' => $startPrice,
                'duration_turns' => $durationTurns,
                'started_turn' => $world->current_turn,
                'ends_turn' => $world->current_turn + $durationTurns,
                'auto_relist' => $autoRelist,
                'status' => AuctionListing::STATUS_ACTIVE,
            ]);
            $this->record($user, $world, $lockedNation, $listing, 'trading_post.listed', [
                'product_type' => 'item', 'item_instance_id' => $lockedItem->id,
                'item_key' => $lockedItem->item_key, 'item_level' => $lockedItem->level,
                'start_price' => $startPrice, 'duration_turns' => $durationTurns,
                'auto_relist' => $autoRelist,
            ]);

            return $listing->fresh(['sellerNation', 'highestBidderNation', 'resourceDefinition']);
        });
    }

    public function bid(User $user, Nation $nation, AuctionListing $listing, int $amount): AuctionListing
    {
        return $this->mutate($user, $nation, function (World $world, Nation $lockedNation, TradingPostRules $rules) use (
            $user, $listing, $amount,
        ): AuctionListing {
            $lockedListing = AuctionListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if ($lockedListing->world_id !== $world->id
                || $lockedListing->status !== AuctionListing::STATUS_ACTIVE
                || $lockedListing->ends_turn <= $world->current_turn) {
                throw new DomainException('この出品はすでに終了しています。');
            }
            if ($lockedListing->seller_nation_id === $lockedNation->id) {
                throw new DomainException('自分自身の出品には入札できません。');
            }
            $minimum = $lockedListing->current_price === null
                ? $lockedListing->start_price
                : $lockedListing->current_price + $rules->minimumIncrementMoney;
            if ($amount < $minimum) {
                throw new DomainException("入札額は{$minimum}億円以上で指定してください。");
            }
            if ($lockedListing->product_type === 'item') {
                $this->assertIncomingItemCapacity($user, $lockedListing);
            }

            $previousBid = AuctionBid::query()
                ->where('auction_listing_id', $lockedListing->id)
                ->where('status', AuctionBid::STATUS_HIGHEST)
                ->lockForUpdate()
                ->first();
            $nationIds = array_values(array_unique(array_filter([
                $lockedNation->id,
                $previousBid?->bidder_nation_id,
            ], static fn (mixed $id): bool => is_int($id))));
            sort($nationIds, SORT_NUMERIC);
            /** @var Collection<int, Nation> $lockedNations */
            $lockedNations = Nation::query()->whereIn('id', $nationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $bidder = $lockedNations->get($lockedNation->id);
            if (! $bidder instanceof Nation) {
                throw new DomainException('入札国の状態を確認できません。');
            }
            if ($previousBid instanceof AuctionBid) {
                $previousBidder = $lockedNations->get($previousBid->bidder_nation_id);
                if (! $previousBidder instanceof Nation) {
                    throw new DomainException('前の最高入札国の状態を確認できません。');
                }
                $previousBidder->increment('money', $previousBid->amount);
                $previousBid->update(['status' => AuctionBid::STATUS_REFUNDED, 'refunded_at' => now()]);
                $this->record($user, $world, $previousBidder, $lockedListing, 'trading_post.outbid_refunded', [
                    'bid_id' => $previousBid->id,
                    'refunded_money' => $previousBid->amount,
                    'new_bidder_nation_id' => $bidder->id,
                ]);
                if ($previousBidder->id === $bidder->id) {
                    $bidder->refresh();
                }
            }
            if ($bidder->money < $amount) {
                throw new DomainException('入札資金が不足しています。');
            }
            $bidder->decrement('money', $amount);
            $bid = AuctionBid::query()->create([
                'auction_listing_id' => $lockedListing->id,
                'bidder_nation_id' => $bidder->id,
                'amount' => $amount,
                'status' => AuctionBid::STATUS_HIGHEST,
                'placed_turn' => $world->current_turn,
            ]);
            $lockedListing->update([
                'current_price' => $amount,
                'highest_bidder_nation_id' => $bidder->id,
                'bid_count' => $lockedListing->bid_count + 1,
            ]);
            $this->record($user, $world, $bidder, $lockedListing, 'trading_post.bid_placed', [
                'bid_id' => $bid->id,
                'amount' => $amount,
                'previous_price' => $previousBid?->amount,
            ]);

            return $lockedListing->fresh(['sellerNation', 'highestBidderNation', 'resourceDefinition']);
        }, $listing->world_id);
    }

    public function cancel(User $user, Nation $nation, AuctionListing $listing): AuctionListing
    {
        return $this->mutate($user, $nation, function (World $world, Nation $lockedNation) use (
            $user, $listing,
        ): AuctionListing {
            $lockedListing = AuctionListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if ($lockedListing->world_id !== $world->id
                || $lockedListing->seller_nation_id !== $lockedNation->id) {
                throw new AuthorizationException('自国の出品だけをキャンセルできます。');
            }
            if ($lockedListing->status !== AuctionListing::STATUS_ACTIVE) {
                throw new DomainException('この出品はすでに終了しています。');
            }
            if ($lockedListing->bid_count > 0) {
                throw new DomainException('入札が入った出品はキャンセルできません。');
            }
            $this->returnEscrow($lockedListing, $lockedNation);
            $lockedListing->update([
                'status' => AuctionListing::STATUS_CANCELLED,
                'completed_turn' => $world->current_turn,
            ]);
            $this->record($user, $world, $lockedNation, $lockedListing, 'trading_post.cancelled', [
                'product_type' => $lockedListing->product_type,
                'quantity' => $lockedListing->quantity,
                'item_instance_id' => $lockedListing->secretary_item_instance_id,
            ]);

            return $lockedListing->fresh(['sellerNation', 'highestBidderNation', 'resourceDefinition']);
        }, expectedWorldId: $listing->world_id, allowDormant: true);
    }

    /**
     * @template T
     *
     * @param  callable(World, Nation, TradingPostRules): T  $operation
     * @return T
     */
    private function mutate(
        User $user,
        Nation $nation,
        callable $operation,
        ?int $expectedWorldId = null,
        bool $allowDormant = false,
    ): mixed {
        $this->authorizeOwner($user, $nation);
        $world = World::query()->findOrFail($nation->world_id);
        if ($expectedWorldId !== null && $world->id !== $expectedWorldId) {
            throw new DomainException('出品と入札国のWorldが一致しません。');
        }
        $this->worldMutationLock->acquire($world);
        try {
            return DB::transaction(function () use ($user, $nation, $world, $operation, $allowDormant): mixed {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                $this->turnRunGuard->assertClear($lockedWorld);
                $lockedNation = Nation::query()->whereKey($nation->id)
                    ->where('world_id', $lockedWorld->id)->lockForUpdate()->firstOrFail();
                $allowedStates = $allowDormant ? ['active', 'dormant', 'recovery'] : ['active', 'recovery'];
                if (! in_array($lockedNation->state, $allowedStates, true)) {
                    throw new DomainException('現役ではない島は交易場を操作できません。');
                }
                $this->authorizeOwner($user, $lockedNation, true);

                return $operation($lockedWorld, $lockedNation, TradingPostRules::fromSettings($ruleset->settings));
            }, 3);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    private function assertListingInput(
        Nation $nation,
        TradingPostRules $rules,
        int $startPrice,
        int $durationTurns,
    ): void {
        if ($startPrice < 1) {
            throw new DomainException('開始価格は1億円以上で指定してください。');
        }
        if ($durationTurns < $rules->minimumDurationTurns || $durationTurns > $rules->maximumDurationTurns) {
            throw new DomainException(
                "出品期間は{$rules->minimumDurationTurns}～{$rules->maximumDurationTurns}ターンで指定してください。",
            );
        }
        $activeIds = AuctionListing::query()->where('seller_nation_id', $nation->id)
            ->where('status', AuctionListing::STATUS_ACTIVE)->lockForUpdate()->pluck('id')->all();
        if (count($activeIds) >= $rules->activeListingLimit) {
            throw new DomainException('同時に出品できる商品は1国家につき3件までです。');
        }
    }

    private function assertIncomingItemCapacity(User $user, AuctionListing $listing): void
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $ownedItems = SecretaryItemInstance::query()->where('secretary_id', $secretary->id)
            ->lockForUpdate()->get(['id', 'item_key'])->all();
        $ownedNationIds = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->pluck('nation_id');
        // Settlement locks its listing before this same Secretary row. Keep cross-World reservations
        // as an MVCC read so bids follow that order and cannot deadlock an official Turn.
        $incomingListings = AuctionListing::query()
            ->where('product_type', 'item')
            ->where('status', AuctionListing::STATUS_ACTIVE)
            ->whereIn('highest_bidder_nation_id', $ownedNationIds)
            ->where('id', '<>', $listing->id)
            ->orderBy('id')
            ->get(['id', 'item_key'])
            ->all();
        if (count($ownedItems) + count($incomingListings) >= SecretaryItemGrantService::INVENTORY_CAPACITY) {
            throw new DomainException('秘書の倉庫に落札アイテムを受け取る空きがありません。');
        }
        if (! is_string($listing->item_key)) {
            throw new DomainException('交易場の商品アイテム定義が不正です。');
        }
        if ($this->items->definition($listing->item_key)['unique_per_secretary']) {
            foreach ([...$ownedItems, ...$incomingListings] as $reservedItem) {
                if ($reservedItem->item_key === $listing->item_key) {
                    throw new DomainException('このアイテムは秘書1人につき1個だけ所有できます。');
                }
            }
        }
    }

    private function returnEscrow(AuctionListing $listing, Nation $seller): void
    {
        if ($listing->product_type === 'resource') {
            $balance = NationResource::query()->where('nation_id', $seller->id)
                ->where('resource_definition_id', $listing->resource_definition_id)
                ->lockForUpdate()->firstOrFail();
            $balance->increment('amount', $listing->quantity);

            return;
        }
        $item = SecretaryItemInstance::query()->whereKey($listing->secretary_item_instance_id)
            ->lockForUpdate()->firstOrFail();
        if (! $item->is_escrowed) {
            throw new DomainException('出品アイテムのescrow状態が不正です。');
        }
        $item->update(['is_escrowed' => false]);
    }

    private function authorizeOwner(User $user, Nation $nation, bool $lock = false): void
    {
        $query = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner');
        $membership = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $membership instanceof NationMembership) {
            throw new AuthorizationException('自国の交易だけを操作できます。');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        User $actor,
        World $world,
        Nation $nation,
        AuctionListing $listing,
        string $eventType,
        array $metadata,
    ): void {
        $now = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $actor->id,
            'world_id' => $world->id,
            'turn' => $world->current_turn,
            'nation_id' => $nation->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'admin',
            'event_type' => $eventType,
            'severity' => 'info',
            'subject_type' => AuctionListing::class,
            'subject_id' => $listing->id,
            'metadata' => json_encode([
                'auction_listing_id' => $listing->id,
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                ...$metadata,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @param  array<int|string, bool>  $viewerBidListingIds
     * @return array<string, mixed>
     */
    private function presentListing(
        AuctionListing $listing,
        Nation $viewer,
        int $currentTurn,
        TradingPostRules $rules,
        array $rulesetSettings,
        array $viewerBidListingIds,
    ): array {
        $item = $listing->item_key === null ? null : $this->items->definition($listing->item_key);
        $itemEffectText = $listing->product_type === 'item'
            && $listing->item_key !== null
            && $listing->item_level !== null
            ? $this->itemGameplay->effectText($rulesetSettings, $listing->item_key, $listing->item_level)
            : null;
        $sellerName = $listing->seller_type === 'hakoniwa_federation'
            ? $rules->npcSellerName
            : $listing->sellerNation?->name;
        $productName = $listing->product_type === 'resource'
            ? $listing->resourceDefinition?->name
            : $item['name'];
        $viewerBidStatus = match (true) {
            $listing->seller_nation_id === $viewer->id => 'seller',
            $listing->highest_bidder_nation_id === $viewer->id => 'highest',
            isset($viewerBidListingIds[$listing->id]) => 'outbid',
            default => 'none',
        };

        return [
            'id' => $listing->id,
            'seller' => [
                'type' => $listing->seller_type,
                'nation_id' => $listing->seller_nation_id,
                'name' => $sellerName,
            ],
            'product' => [
                'type' => $listing->product_type,
                'name' => $productName,
                'resource_key' => $listing->resourceDefinition?->key,
                'unit_label' => $listing->resourceDefinition?->unit_label,
                'quantity' => $listing->quantity,
                'item_key' => $listing->item_key,
                'item_level' => $listing->item_level,
                'rarity' => $item['rarity'] ?? null,
                'rarity_label' => $item['rarity_label'] ?? null,
                'effect_text' => $itemEffectText,
            ],
            'start_price' => $listing->start_price,
            'current_price' => $listing->current_price,
            'minimum_bid' => $listing->current_price === null
                ? $listing->start_price
                : $listing->current_price + $rules->minimumIncrementMoney,
            'bid_count' => $listing->bid_count,
            'highest_bidder_nation_id' => $listing->highest_bidder_nation_id,
            'highest_bidder' => $listing->highestBidderNation instanceof Nation
                ? [
                    'nation_id' => $listing->highestBidderNation->id,
                    'name' => $listing->highestBidderNation->name,
                ]
                : null,
            'viewer_bid_status' => $viewerBidStatus,
            'started_turn' => $listing->started_turn,
            'ends_turn' => $listing->ends_turn,
            'remaining_turns' => max(0, $listing->ends_turn - $currentTurn),
            'duration_turns' => $listing->duration_turns,
            'auto_relist' => $listing->auto_relist,
            'relist_count' => $listing->relist_count,
            'is_mine' => $listing->seller_nation_id === $viewer->id,
            'can_bid' => in_array($viewer->state, ['active', 'recovery'], true)
                && $listing->seller_nation_id !== $viewer->id,
            'can_cancel' => $listing->seller_nation_id === $viewer->id
                && $listing->bid_count === 0,
        ];
    }
}
