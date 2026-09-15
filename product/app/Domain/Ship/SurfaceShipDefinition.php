<?php

namespace App\Domain\Ship;

final readonly class SurfaceShipDefinition
{
    public function __construct(
        public ?int $selector,
        public string $key,
        public string $name,
        public string $assetKey,
        public bool $playerBuildable,
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
        if (! $this->playerBuildable || $this->selector === null) {
            throw new \LogicException('NPC-only Surface Ship definitions have no Player build presentation.');
        }

        return [
            'value' => $this->selector,
            'key' => $this->key,
            'label' => $this->name,
            'cost_money' => $this->buildCostMoney,
        ];
    }
}
