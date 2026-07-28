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
        int $inventoryUnitsPerMoney = 1_000,
    ): InventorySaleQuote {
        if ($inventoryUnits < 0 || $inventoryUnitsPerMoney < 1) {
            throw new DomainException('Inventory sale values are invalid.');
        }

        $requestedMoney = intdiv($inventoryUnits, $inventoryUnitsPerMoney);
        $money = $this->addition->calculate($moneyBefore, $requestedMoney, $moneyCapacity);
        $inventorySold = $money->applied * $inventoryUnitsPerMoney;

        return new InventorySaleQuote(
            inventoryBefore: $inventoryUnits,
            inventorySold: $inventorySold,
            inventoryRemaining: $inventoryUnits - $inventorySold,
            requestedMoney: $money->requested,
            appliedMoney: $money->applied,
            overflowMoney: $money->overflow,
            moneyBefore: $money->before,
            moneyAfter: $money->after,
            moneyCapacity: $money->capacity,
            inventoryUnitsPerMoney: $inventoryUnitsPerMoney,
        );
    }
}
