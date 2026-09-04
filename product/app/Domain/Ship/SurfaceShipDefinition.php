<?php

namespace App\Domain\Ship;

final readonly class SurfaceShipDefinition
{
    public function __construct(
        public int $selector,
        public string $key,
        public string $name,
        public string $assetKey,
        public int $sortOrder,
        public int $buildCostMoney,
        public int $maximumHp,
        public int $movementOilUnits,
        public ?string $movementRewardResourceKey,
        public int $movementRewardResourceUnits,
        public int $movementRewardMoney,
        public int $visibilityRadius,
    ) {}

    /** @return array{value: int, key: string, label: string, cost_money: int} */
    public function presentation(): array
    {
        return [
            'value' => $this->selector,
            'key' => $this->key,
            'label' => $this->name,
            'cost_money' => $this->buildCostMoney,
        ];
    }
}
