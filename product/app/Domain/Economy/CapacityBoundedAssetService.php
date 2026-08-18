<?php

namespace App\Domain\Economy;

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
            $result = $this->addition->calculate((int) $lockedNation->money, $requested, $capacity);
            if ($result->applied > 0) {
                $lockedNation->increment('money', $result->applied);
            }

            return $result;
        }, 1);
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
