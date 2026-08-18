<?php

namespace App\Application;

use App\Domain\Economy\CapacityAdditionResult;
use App\Domain\Economy\InventorySalePlanner;
use App\Domain\Economy\NationCapacities;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FoodOverflowResolver
{
    public function __construct(
        private readonly NationCapacityResolver $capacities,
        private readonly InventorySalePlanner $sales,
        private readonly TurnEventRecorder $events,
    ) {}

    /**
     * @return array{
     *     requested_overflow_tons: int,
     *     sold_tons: int,
     *     revenue: int,
     *     discarded_tons: int,
     *     food_capacity_tons: int,
     *     money_capacity: int
     * }
     */
    public function resolve(
        TurnContext $context,
        Nation $nation,
        ResourceDefinition $resource,
        CapacityAdditionResult $foodCredit,
    ): array {
        if ($resource->category !== 'food') {
            throw new DomainException('Food overflow can only resolve a resource in category=food.');
        }

        if ($foodCredit->overflow === 0) {
            return [
                'requested_overflow_tons' => 0,
                'sold_tons' => 0,
                'revenue' => 0,
                'discarded_tons' => 0,
                'food_capacity_tons' => $foodCredit->capacity,
                'money_capacity' => $this->capacities->resolve($nation, $context->ruleset)->money,
            ];
        }

        return DB::transaction(function () use ($context, $nation, $resource, $foodCredit): array {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $capacity = $this->capacities->resolve($lockedNation, $context->ruleset);

            return $this->resolveLocked($context, $lockedNation, $resource, $foodCredit, $capacity);
        }, 1);
    }

    /**
     * Removes only the residual portion of this turn's production after
     * population nutrition has consumed from the aggregate food inventory.
     *
     * @return array{
     *     requested_overflow_tons: int,
     *     sold_tons: int,
     *     revenue: int,
     *     discarded_tons: int,
     *     food_capacity_tons: int,
     *     money_capacity: int
     * }
     */
    public function resolveAfterNutrition(
        TurnContext $context,
        Nation $nation,
        ResourceDefinition $productionResource,
        CapacityAdditionResult $foodCredit,
    ): array {
        if ($productionResource->category !== 'food' || $foodCredit->requested < 0) {
            throw new DomainException('Post-nutrition overflow requires non-negative food production.');
        }

        return DB::transaction(function () use ($context, $nation, $productionResource, $foodCredit): array {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $foodBalances = NationResource::query()
                ->where('nation_id', $lockedNation->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->lockForUpdate()
                ->get();
            $capacity = $this->capacities->resolve($lockedNation, $context->ruleset);
            if ($foodCredit->capacity !== $capacity->foodTons) {
                throw new DomainException('Food capacity changed between production and overflow resolution.');
            }
            $foodAfterNutrition = (int) $foodBalances->sum('amount');
            $protectedHistoricalAmount = max($capacity->foodTons, $foodCredit->before);
            $overflowTons = min(
                $foodCredit->requested,
                max(0, $foodAfterNutrition - $protectedHistoricalAmount),
            );
            $resolvedCredit = new CapacityAdditionResult(
                before: $foodCredit->before,
                requested: $foodCredit->requested,
                applied: $foodCredit->requested - $overflowTons,
                overflow: $overflowTons,
                after: $foodAfterNutrition - $overflowTons,
                capacity: $capacity->foodTons,
            );
            if ($overflowTons === 0) {
                return $this->emptyResolution($capacity);
            }

            $productionBalance = $foodBalances->firstWhere('resource_definition_id', $productionResource->id);
            if (! $productionBalance instanceof NationResource || $productionBalance->amount < $overflowTons) {
                throw new DomainException('Residual food overflow cannot be removed from turn production.');
            }
            $productionBalance->decrement('amount', $overflowTons);

            return $this->resolveLocked($context, $lockedNation, $productionResource, $resolvedCredit, $capacity);
        }, 1);
    }

    /** @return array{requested_overflow_tons: int, sold_tons: int, revenue: int, discarded_tons: int, food_capacity_tons: int, money_capacity: int} */
    private function emptyResolution(NationCapacities $capacity): array
    {
        return [
            'requested_overflow_tons' => 0,
            'sold_tons' => 0,
            'revenue' => 0,
            'discarded_tons' => 0,
            'food_capacity_tons' => $capacity->foodTons,
            'money_capacity' => $capacity->money,
        ];
    }

    /** @return array{requested_overflow_tons: int, sold_tons: int, revenue: int, discarded_tons: int, food_capacity_tons: int, money_capacity: int} */
    private function resolveLocked(
        TurnContext $context,
        Nation $lockedNation,
        ResourceDefinition $resource,
        CapacityAdditionResult $foodCredit,
        NationCapacities $capacity,
    ): array {
        if ($foodCredit->capacity !== $capacity->foodTons) {
            throw new DomainException('Food capacity changed while resolving hard-cap overflow.');
        }
        $rate = $context->ruleset->settings['inventory_sale_rates'][$resource->key] ?? null;
        if (! is_array($rate)
            || ! is_int($rate['inventory_units'] ?? null)
            || $rate['inventory_units'] < 1
            || ! is_int($rate['money_units'] ?? null)
            || $rate['money_units'] < 1) {
            throw new DomainException("Inventory sale rate is missing or invalid for {$resource->key}.");
        }
        $quote = $this->sales->plan(
            $foodCredit->overflow,
            (int) $lockedNation->money,
            $capacity->money,
            $rate['inventory_units'],
            $rate['money_units'],
        );
        if ($quote->appliedMoney > 0) {
            $lockedNation->increment('money', $quote->appliedMoney);
        }

        $resolution = [
            'requested_overflow_tons' => $foodCredit->overflow,
            'sold_tons' => $quote->inventorySold,
            'revenue' => $quote->appliedMoney,
            'discarded_tons' => $quote->inventoryRemaining,
            'food_capacity_tons' => $foodCredit->capacity,
            'money_capacity' => $capacity->money,
        ];
        $this->events->record(
            $context,
            'resource.food_overflow_resolved',
            $lockedNation,
            [
                'resource_key' => $resource->key,
                ...$resolution,
                'money_before' => $quote->moneyBefore,
                'money_after' => $quote->moneyAfter,
                'inventory_units_per_batch' => $rate['inventory_units'],
                'money_units_per_batch' => $rate['money_units'],
            ],
            'nation',
            $quote->inventoryRemaining > 0 ? 'warning' : 'info',
        );

        return $resolution;
    }
}
