<?php

namespace App\Application\Underground;

use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundMarketListing;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All writers lock profiles in ID order, then listings, then equipment. */
final readonly class UndergroundMarketService
{
    public function __construct(private UndergroundEquipmentLoadoutResolver $loadout, private UndergroundEquipmentCatalog $catalog) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function browse(User $user, array $filters): array
    {
        return DB::transaction(function () use ($user, $filters): array {
            $profile = $this->openProfile($user);
            $cap = $this->purchaseIlCap($profile);
            $inventory = $this->inventory($profile);
            $query = UndergroundMarketListing::query()->where('status', 'active');
            $rows = $query->orderByDesc('id')->get()->map(function (UndergroundMarketListing $listing) use ($profile, $cap, $inventory): array {
                $item = $listing->item_snapshot;
                $reasons = [];
                if ($listing->seller_profile_id === $profile->id) {
                    $reasons[] = 'own_listing';
                }
                if ($item['item_level'] > $cap) {
                    $reasons[] = 'il_cap_exceeded';
                }
                if ($listing->price > $profile->shard_balance) {
                    $reasons[] = 'insufficient_carried_shards';
                }
                $kind = $item['category'] === 'resonance' ? 'resonance' : 'equipment';
                if ($inventory[$kind]['used'] >= $inventory[$kind]['capacity']) {
                    $reasons[] = $kind === 'resonance' ? 'resonance_full' : 'vault_full';
                }

                return ['id' => $listing->id, 'item' => $item, 'seller' => $listing->seller_snapshot,
                    'price' => $listing->price, 'expires_at' => null,
                    'is_own' => $listing->seller_profile_id === $profile->id,
                    'purchasable' => $item['item_level'] <= $cap, 'blocked_reasons' => $reasons];
            })->filter(static function (array $row) use ($filters): bool {
                $item = $row['item'];

                return (! ($filters['purchasable_only'] ?? false) || $row['purchasable'])
                    && (! isset($filters['category']) || $item['category'] === $filters['category'])
                    && (! isset($filters['weapon_style']) || ($item['weapon_style'] ?? null) === $filters['weapon_style'])
                    && ($item['item_level'] >= ($filters['il_min'] ?? 0))
                    && (($item['quality_percent'] ?? 0) >= ($filters['quality_min'] ?? 0));
            })->values();
            $sort = $filters['sort'] ?? 'newest';
            if ($sort === 'price') {
                $rows = $rows->sortBy([['price', 'asc'], ['id', 'desc']])->values();
            }
            if ($sort === 'item_level') {
                $rows = $rows->sortBy([['item.item_level', 'desc'], ['id', 'desc']])->values();
            }
            $page = (int) ($filters['page'] ?? 1);
            $size = $this->rules()['page_size'];

            return ['purchase_il_cap' => $cap, 'carried_shards' => $profile->shard_balance,
                'fee' => ['numerator' => $this->rules()['proceeds_denominator'] - $this->rules()['proceeds_numerator'], 'denominator' => $this->rules()['proceeds_denominator']],
                'listings' => $rows->slice(($page - 1) * $size, $size)->values()->all(),
                'page' => $page, 'has_more' => $rows->count() > $page * $size];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function listable(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $profile = $this->openProfile($user);
            $rating = $this->loadout->ratingRequirementBps($profile);

            return ['items' => UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)->orderBy('id')->get()
                ->map(fn (UndergroundOwnedEquipment $item): array => $this->loadout->projectOwned($item, $rating))->all(),
                'listing_limit' => $this->rules()['listing_limit'], 'active_count' => $this->activeCount($profile)];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function mine(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $profile = $this->openProfile($user);
            $rows = UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->orderByDesc('id')->get();
            $project = fn (UndergroundMarketListing $listing): array => $this->project($listing);

            return ['listing_limit' => $this->rules()['listing_limit'],
                'active' => $rows->where('status', 'active')->map($project)->values()->all(),
                'sold' => $rows->where('status', 'sold')->map($project)->values()->all(),
                'expired' => $rows->where('status', 'expired')->map($project)->values()->all(),
                'unclaimed_total' => $this->attentionForProfile($profile)['unclaimed_total']];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function create(User $user, string $requestId, int $equipmentId, int $price): array
    {
        return $this->mutate($user, $requestId, ['create', $equipmentId, $price], null,
            function (UndergroundProfile $profile) use ($equipmentId, $price): array {
                if ($this->activeCount($profile) >= $this->rules()['listing_limit']) {
                    $this->fail('listing_limit', '同時に出品できる数の上限です。');
                }
                $item = UndergroundOwnedEquipment::query()->whereKey($equipmentId)->where('underground_profile_id', $profile->id)->lockForUpdate()->first();
                if (! $item instanceof UndergroundOwnedEquipment) {
                    $this->fail('not_listable', '出品する品物が見つかりません。');
                }
                $eligibility = $this->loadout->marketListability($item);
                if (! $eligibility['listable']) {
                    $this->fail('not_listable', $eligibility['listable_reason'] ?? 'この品物は出品できません。');
                }
                if ($price < $eligibility['market_minimum_price']) {
                    $this->fail('below_minimum_price', 'NPC売値以上の価格を指定してください。');
                }
                $rules = $this->rules();
                $proceeds = intdiv($price, $rules['proceeds_denominator']) * $rules['proceeds_numerator']
                    + intdiv(($price % $rules['proceeds_denominator']) * $rules['proceeds_numerator'], $rules['proceeds_denominator']);
                $snapshot = $this->loadout->projectOwned($item);
                $listing = UndergroundMarketListing::query()->create([
                    'seller_profile_id' => $profile->id, 'equipment_id' => $item->id,
                    'price' => $price, 'fee' => $price - $proceeds, 'proceeds' => $proceeds, 'status' => 'active',
                    'item_snapshot' => $snapshot,
                    'seller_snapshot' => ['secretary_name' => $profile->secretary->name,
                        'island_name' => NationMembership::query()->where('user_id', $profile->secretary->user_id)->where('role', 'owner')->with('nation')->first()?->nation->name],
                    'listed_at' => now(), 'expires_at' => null,
                ]);
                $item->update(['is_listed' => true]);

                return ['listing' => $this->project($listing), 'active_count' => $this->activeCount($profile), 'listing_limit' => $rules['listing_limit']];
            });
    }

    /** @return array<string,mixed> */
    public function purchase(User $user, string $requestId, int $listingId, int $expectedPrice): array
    {
        $sellerId = UndergroundMarketListing::query()->whereKey($listingId)->value('seller_profile_id');

        return $this->mutate($user, $requestId, ['purchase', $listingId, $expectedPrice], $sellerId === null ? null : (int) $sellerId,
            function (UndergroundProfile $profile) use ($listingId, $expectedPrice): array {
                $listing = UndergroundMarketListing::query()->whereKey($listingId)->lockForUpdate()->first();
                if (! $listing instanceof UndergroundMarketListing || $listing->status !== 'active') {
                    $this->fail('listing_unavailable', 'この出品は終了しました。');
                }
                if ($listing->seller_profile_id === $profile->id) {
                    $this->fail('own_listing', '自分の出品は購入できません。');
                }
                if ($listing->price !== $expectedPrice) {
                    $this->fail('price_changed', '価格が変わりました。確認し直してください。');
                }
                $item = UndergroundOwnedEquipment::query()->whereKey($listing->equipment_id)->where('underground_profile_id', $listing->seller_profile_id)->lockForUpdate()->first();
                if (! $item instanceof UndergroundOwnedEquipment || ! $item->is_listed || $item->equipped_slot !== null) {
                    $this->fail('listing_unavailable', 'この出品は終了しました。');
                }
                $definition = $this->loadout->definitionForRow($item);
                if ($definition['item_level'] > $this->purchaseIlCap($profile)) {
                    $this->fail('il_cap_exceeded', '購入できるIL上限を超えています。');
                }
                if ($profile->shard_balance < $listing->price) {
                    $this->fail('insufficient_carried_shards', '手持ちのGが足りません。');
                }
                $kind = $definition['category'] === 'resonance' ? 'resonance' : 'equipment';
                $vault = $this->inventory($profile)[$kind];
                if ($vault['used'] >= $vault['capacity']) {
                    $this->fail($kind === 'resonance' ? 'resonance_full' : 'vault_full', '保管庫に空きがありません。');
                }
                $profile->update(['shard_balance' => $profile->shard_balance - $listing->price]);
                $item->update(['underground_profile_id' => $profile->id, 'is_listed' => false,
                    'source_market_listing_id' => $listing->id, 'acquired_at' => now()]);
                $listing->update(['status' => 'sold', 'buyer_profile_id' => $profile->id, 'sold_at' => now()]);

                return ['listing_id' => $listing->id, 'equipment_id' => $item->id, 'paid' => $listing->price,
                    'carried_shards' => $profile->shard_balance, 'vault' => ['used' => $vault['used'] + 1, 'capacity' => $vault['capacity']]];
            });
    }

    /** @return array<string,mixed> */
    public function cancel(User $user, int $listingId): array
    {
        return DB::transaction(function () use ($user, $listingId): array {
            $profile = $this->openProfile($user);
            $listing = UndergroundMarketListing::query()->whereKey($listingId)->where('seller_profile_id', $profile->id)->lockForUpdate()->first();
            if (! $listing instanceof UndergroundMarketListing) {
                $this->fail('listing_unavailable', '出品が見つかりません。');
            }
            if ($listing->status === 'active') {
                $this->release($listing, 'cancelled');
            } elseif ($listing->status === 'sold') {
                $this->fail('listing_unavailable', 'この品物は売却済みです。');
            }

            return ['listing_id' => $listing->id, 'status' => $listing->status];
        }, 3);
    }

    /**
     * @param  list<int>|null  $ids
     * @return array<string,mixed>
     */
    public function claim(User $user, string $requestId, ?array $ids): array
    {
        if ($ids !== null) {
            sort($ids, SORT_NUMERIC);
        }

        return $this->mutate($user, $requestId, ['claim', $ids], null, function (UndergroundProfile $profile) use ($ids): array {
            $query = UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->where('status', 'sold')->whereNull('proceeds_claimed_at');
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }
            $rows = $query->orderBy('id')->lockForUpdate()->get();
            $total = 0;
            foreach ($rows as $row) {
                if ($row->proceeds > PHP_INT_MAX - $total || $row->proceeds > PHP_INT_MAX - $profile->shard_balance - $total) {
                    $this->fail('balance_overflow', '手持ち残高の上限を超えます。');
                }
                $total += $row->proceeds;
            }
            foreach ($rows as $row) {
                $row->update(['proceeds_claimed_at' => now()]);
            }
            $profile->update(['shard_balance' => $profile->shard_balance + $total]);

            return ['claimed' => $total, 'claimed_listing_ids' => $rows->modelKeys(), 'carried_shards' => $profile->shard_balance];
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array<string,mixed>
     */
    public function seen(User $user, array $ids): array
    {
        return DB::transaction(function () use ($user, $ids): array {
            $profile = $this->openProfile($user);
            UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->whereIn('id', $ids)
                ->where('status', 'expired')->whereNull('seen_by_seller_at')->update(['seen_by_seller_at' => now()]);

            return ['market_attention' => $this->attentionForProfile($profile)];
        }, 3);
    }

    /** @return array{unclaimed_count:int,unclaimed_total:int,unseen_expired_count:int} */
    public function attentionForProfile(?UndergroundProfile $profile): array
    {
        if ($profile === null) {
            return ['unclaimed_count' => 0, 'unclaimed_total' => 0, 'unseen_expired_count' => 0];
        }

        return DB::transaction(function () use ($profile): array {
            UndergroundProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $unclaimed = UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->where('status', 'sold')->whereNull('proceeds_claimed_at');

            return ['unclaimed_count' => $unclaimed->count(), 'unclaimed_total' => (int) $unclaimed->sum('proceeds'),
                'unseen_expired_count' => UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->where('status', 'expired')->whereNull('seen_by_seller_at')->count()];
        }, 3);
    }

    /** Island abandonment retains Secretary assets and unclaimed proceeds, and cancels active listings. */
    public function settleForAbandonment(int $userId): void
    {
        $profile = UndergroundProfile::query()->whereHas('secretary', fn ($q) => $q->where('user_id', $userId))->lockForUpdate()->first();
        if (! $profile instanceof UndergroundProfile) {
            return;
        }
        foreach (UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $listing) {
            $this->release($listing, 'cancelled');
        }
    }

    private function release(UndergroundMarketListing $listing, string $status): void
    {
        UndergroundOwnedEquipment::query()->whereKey($listing->equipment_id)->where('underground_profile_id', $listing->seller_profile_id)->update(['is_listed' => false]);
        $listing->update(['status' => $status]);
    }

    /**
     * @param  list<mixed>  $payload
     * @param  callable(UndergroundProfile):array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function mutate(User $user, string $requestId, array $payload, ?int $otherId, callable $operation): array
    {
        if (! Str::isUuid($requestId)) {
            $this->fail('request_conflict', '受付キーを確認してください。');
        }
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $requestId, $fingerprint, $otherId, $operation): array {
            $profileId = UndergroundProfile::query()->whereHas('secretary', fn ($q) => $q->where('user_id', $user->id))->value('id');
            $ids = array_values(array_unique(array_filter([$profileId, $otherId])));
            sort($ids, SORT_NUMERIC);
            UndergroundProfile::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $profile = $this->openProfile($user);
            $stored = DB::table('underground_market_receipts')->where('underground_profile_id', $profile->id)->where('request_id', $requestId)->first();
            if ($stored !== null) {
                if ($stored->request_fingerprint !== $fingerprint) {
                    $this->fail('request_conflict', '同じ受付キーで異なる操作はできません。');
                }

                return json_decode($stored->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $operation($profile);
            DB::table('underground_market_receipts')->insert(['underground_profile_id' => $profile->id, 'request_id' => $requestId,
                'request_fingerprint' => $fingerprint, 'result_snapshot' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    private function openProfile(User $user): UndergroundProfile
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $profile = $secretary === null ? null : UndergroundProfile::query()->where('secretary_id', $secretary->id)->lockForUpdate()->first();
        if (! $profile instanceof UndergroundProfile || $secretary->name === null || $profile->growth_path_key === null
            || ! UndergroundIntroProgress::query()->where('underground_profile_id', $profile->id)->where('stage', UndergroundIntroStage::UNDERGROUND_OPEN)->exists()) {
            $this->fail('underground_equipment_locked', '交流場はまだ解禁されていません。');
        }
        app(UndergroundRequestAdmission::class)->assertLockedProfile($profile);
        $profile->setRelation('secretary', $secretary);

        return $profile;
    }

    private function purchaseIlCap(UndergroundProfile $profile): int
    {
        $cap = 0;
        foreach (UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)->get() as $item) {
            $definition = $this->loadout->definitionForRow($item);
            if (($definition['equippable'] ?? true) === true) {
                $cap = max($cap, $definition['item_level']);
            }
        }

        return $cap;
    }

    /** @return array<string,array{used:int,capacity:int}> */
    private function inventory(UndergroundProfile $profile): array
    {
        $result = [];
        foreach (['equipment', 'resonance'] as $kind) {
            $result[$kind] = ['used' => UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)->inventory($kind)->count(),
                'capacity' => $this->catalog->vaultCapacityForProfile($profile, $kind)];
        }

        return $result;
    }

    private function activeCount(UndergroundProfile $profile): int
    {
        return UndergroundMarketListing::query()->where('seller_profile_id', $profile->id)->where('status', 'active')->count();
    }

    /** @return array<string,mixed> */
    private function project(UndergroundMarketListing $listing): array
    {
        return ['id' => $listing->id, 'item' => $listing->item_snapshot, 'item_snapshot' => $listing->item_snapshot,
            'price' => $listing->price, 'fee' => $listing->fee, 'proceeds' => $listing->proceeds,
            'expires_at' => $listing->status === 'active' ? null : $listing->expires_at?->toIso8601String(), 'sold_at' => $listing->sold_at?->toIso8601String(),
            'expired_at' => $listing->expired_at?->toIso8601String(), 'claimed' => $listing->proceeds_claimed_at !== null,
            'seen' => $listing->seen_by_seller_at !== null];
    }

    /** @return array{listing_limit:int,listing_expiration:string,proceeds_numerator:int,proceeds_denominator:int,page_size:int} */
    private function rules(): array
    {
        return config('hakoniwa.ruleset.underground_market');
    }

    private function fail(string $code, string $message): never
    {
        throw new UndergroundRuntimeException($code, $message);
    }
}
