<?php

namespace App\Domain\Economy;

use App\Models\AuctionBid;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CapacityBoundedAssetService
{
    public function __construct(
        private readonly NationCapacityResolver $capacities,
        private readonly CappedAddition $addition,
    ) {}

    public function creditMoney(
        Nation $nation,
        int $requested,
        ?RulesetVersion $ruleset = null,
    ): CapacityAdditionResult {
        return DB::transaction(function () use ($nation, $requested, $ruleset): CapacityAdditionResult {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $capacity = $this->capacities->resolve($lockedNation, $ruleset)->money;
            $result = $this->planMoneyCredit($lockedNation, $requested, $capacity);
            if ($result->applied > 0) {
                $lockedNation->increment('money', $result->applied);
            }

            return $result;
        }, 1);
    }

    public function planMoneyCredit(
        Nation $nation,
        int $requested,
        int $capacity,
        ?int $liquidBefore = null,
    ): CapacityAdditionResult {
        $liquidBefore ??= (int) $nation->money;
        $usage = $this->addition->calculate(
            $liquidBefore + $this->escrowedMoney($nation),
            $requested,
            $capacity,
        );

        return new CapacityAdditionResult(
            before: $liquidBefore,
            requested: $requested,
            applied: $usage->applied,
            overflow: $usage->overflow,
            after: $liquidBefore + $usage->applied,
            capacity: $capacity,
        );
    }

    public function moneyInUse(Nation $nation): int
    {
        return (int) $nation->money + $this->escrowedMoney($nation);
    }

    public function liquidMoneyCapacity(Nation $nation, int $capacity): int
    {
        return max(0, $capacity - $this->escrowedMoney($nation));
    }

    public function escrowedMoney(Nation $nation): int
    {
        return $this->escrowedMoneyByNationIds([$nation->id])[$nation->id] ?? 0;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, int>
     */
    public function escrowedMoneyByNationIds(array $nationIds): array
    {
        if ($nationIds === []) {
            return [];
        }
        $rows = AuctionBid::query()
            ->whereIn('bidder_nation_id', $nationIds)
            ->where('status', AuctionBid::STATUS_HIGHEST)
            ->whereHas('listing', fn ($query) => $query->where('status', AuctionListing::STATUS_ACTIVE))
            ->selectRaw('bidder_nation_id, SUM(amount) AS aggregate')
            ->groupBy('bidder_nation_id')
            ->pluck('aggregate', 'bidder_nation_id');
        $escrowed = [];
        foreach ($rows as $nationId => $amount) {
            $escrowed[(int) $nationId] = (int) $amount;
        }

        return $escrowed;
    }

    public function creditFood(
        Nation $nation,
        ResourceDefinition $resource,
        int $requestedTons,
        ?RulesetVersion $ruleset = null,
    ): CapacityAdditionResult {
        if ($resource->category !== 'food') {
            throw new DomainException('Food capacity can only credit a resource in category=food.');
        }

        return DB::transaction(function () use ($nation, $resource, $requestedTons, $ruleset): CapacityAdditionResult {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $foodBalances = NationResource::query()
                ->where('nation_id', $lockedNation->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->lockForUpdate()
                ->get();
            $before = (int) $foodBalances->sum('amount');
            $capacity = $this->capacities->resolve($lockedNation, $ruleset)->foodTons;
            $result = $this->addition->calculate($before, $requestedTons, $capacity);

            if ($result->applied > 0) {
                $balance = $foodBalances->firstWhere('resource_definition_id', $resource->id);
                $balance ??= NationResource::query()->create([
                    'nation_id' => $lockedNation->id,
                    'resource_definition_id' => $resource->id,
                    'amount' => 0,
                ]);
                $balance->increment('amount', $result->applied);
            }

            return $result;
        }, 1);
    }

    /**
     * Credits one turn's farm production before nutrition is consumed.
     *
     * This is intentionally separate from creditFood(): aid and monster rewards
     * must retain their existing hard-cap behavior.
     */
    public function creditFoodProductionForTurn(
        Nation $nation,
        ResourceDefinition $resource,
        int $requestedTons,
        RulesetVersion $ruleset,
    ): CapacityAdditionResult {
        if ($resource->category !== 'food') {
            throw new DomainException('Turn food production can only credit a resource in category=food.');
        }
        if ($requestedTons < 0) {
            throw new DomainException('Turn food production requires a non-negative amount.');
        }

        return DB::transaction(function () use ($nation, $resource, $requestedTons, $ruleset): CapacityAdditionResult {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $foodBalances = NationResource::query()
                ->where('nation_id', $lockedNation->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->lockForUpdate()
                ->get();
            $before = (int) $foodBalances->sum('amount');
            $capacity = $this->capacities->resolve($lockedNation, $ruleset)->foodTons;

            if ($requestedTons > 0) {
                $balance = $foodBalances->firstWhere('resource_definition_id', $resource->id);
                $balance ??= NationResource::query()->create([
                    'nation_id' => $lockedNation->id,
                    'resource_definition_id' => $resource->id,
                    'amount' => 0,
                ]);
                $balance->increment('amount', $requestedTons);
            }

            return new CapacityAdditionResult(
                before: $before,
                requested: $requestedTons,
                applied: $requestedTons,
                overflow: max(0, $before + $requestedTons - max($capacity, $before)),
                after: $before + $requestedTons,
                capacity: $capacity,
            );
        }, 1);
    }
}
