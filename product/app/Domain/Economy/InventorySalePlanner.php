<?php

namespace App\Domain\Economy;

use DomainException;

final class InventorySalePlanner
{
    public function __construct(private readonly CappedAddition $addition) {}

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
