<?php

namespace App\Domain\Economy;

use DomainException;

final class InventorySalePlanner
{
    public function __construct(private readonly CappedAddition $addition) {}

    /** @param array<string, mixed> $rates */
    public function planForResource(
        array $rates,
        string $resourceKey,
        int $inventoryUnits,
        int $moneyBefore,
        int $moneyCapacity,
    ): InventorySaleQuote {
        $rate = $this->rateForResource($rates, $resourceKey);

        return $this->plan(
            $inventoryUnits,
            $moneyBefore,
            $moneyCapacity,
            $rate['inventory_units'],
            $rate['money_units'],
        );
    }

    /**
     * @param  array<string, mixed>  $rates
     * @return array{inventory_units: int, money_units: int}
     */
    public function rateForResource(array $rates, string $resourceKey): array
    {
        $rate = $rates[$resourceKey] ?? null;
        if (! is_array($rate)
            || ! is_int($rate['inventory_units'] ?? null)
            || $rate['inventory_units'] < 1
            || ! is_int($rate['money_units'] ?? null)
            || $rate['money_units'] < 1) {
            throw new DomainException("Inventory sale rate is missing or invalid for {$resourceKey}.");
        }

        return $rate;
    }

    public function plan(
        int $inventoryUnits,
        int $moneyBefore,
        int $moneyCapacity,
        int $inventoryUnitsPerBatch = 1_000,
        int $moneyUnitsPerBatch = 1,
    ): InventorySaleQuote {
        if ($inventoryUnits < 0 || $inventoryUnitsPerBatch < 1 || $moneyUnitsPerBatch < 1) {
            throw new DomainException('Inventory sale values are invalid.');
        }

        $requestedBatches = intdiv($inventoryUnits, $inventoryUnitsPerBatch);
        $requestedMoney = $requestedBatches * $moneyUnitsPerBatch;
        $money = $this->addition->calculate($moneyBefore, $requestedMoney, $moneyCapacity);
        $soldBatches = min($requestedBatches, intdiv($money->applied, $moneyUnitsPerBatch));
        $inventorySold = $soldBatches * $inventoryUnitsPerBatch;
        $appliedMoney = $soldBatches * $moneyUnitsPerBatch;

        return new InventorySaleQuote(
            inventoryBefore: $inventoryUnits,
            inventorySold: $inventorySold,
            inventoryRemaining: $inventoryUnits - $inventorySold,
            requestedMoney: $money->requested,
            appliedMoney: $appliedMoney,
            overflowMoney: $money->requested - $appliedMoney,
            moneyBefore: $money->before,
            moneyAfter: $money->before + $appliedMoney,
            moneyCapacity: $money->capacity,
            inventoryUnitsPerBatch: $inventoryUnitsPerBatch,
            moneyUnitsPerBatch: $moneyUnitsPerBatch,
        );
    }
}
