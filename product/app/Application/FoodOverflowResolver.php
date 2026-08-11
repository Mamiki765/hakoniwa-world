<?php

namespace App\Application;

use App\Domain\Economy\CapacityAdditionResult;
use App\Domain\Economy\InventorySalePlanner;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Turn\TurnContext;
use App\Models\Nation;
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

        $rate = $context->ruleset->settings['inventory_sale_rates'][$resource->key] ?? null;
        if (! is_array($rate)
            || ! is_int($rate['inventory_units'] ?? null)
            || $rate['inventory_units'] < 1
            || ! is_int($rate['money_units'] ?? null)
            || $rate['money_units'] < 1) {
            throw new DomainException("Inventory sale rate is missing or invalid for {$resource->key}.");
        }

        return DB::transaction(function () use ($context, $nation, $resource, $foodCredit, $rate): array {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $capacity = $this->capacities->resolve($lockedNation, $context->ruleset);
            if ($foodCredit->capacity !== $capacity->foodTons) {
                throw new DomainException('Food capacity changed while resolving hard-cap overflow.');
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
        }, 1);
    }
}
