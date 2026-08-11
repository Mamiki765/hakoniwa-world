<?php

namespace App\Domain\Economy;

final readonly class InventorySaleQuote
{
    public function __construct(
        public int $inventoryBefore,
        public int $inventorySold,
        public int $inventoryRemaining,
        public int $requestedMoney,
        public int $appliedMoney,
        public int $overflowMoney,
        public int $moneyBefore,
        public int $moneyAfter,
        public int $moneyCapacity,
        public int $inventoryUnitsPerBatch,
        public int $moneyUnitsPerBatch,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'inventory_before' => $this->inventoryBefore,
            'inventory_sold' => $this->inventorySold,
            'inventory_remaining' => $this->inventoryRemaining,
            'requested_money' => $this->requestedMoney,
            'applied_money' => $this->appliedMoney,
            'overflow_money' => $this->overflowMoney,
            'money_before' => $this->moneyBefore,
            'money_after' => $this->moneyAfter,
            'money_capacity' => $this->moneyCapacity,
            'inventory_units_per_batch' => $this->inventoryUnitsPerBatch,
            'money_units_per_batch' => $this->moneyUnitsPerBatch,
        ];
    }
}
