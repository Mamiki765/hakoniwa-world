<?php

namespace App\Domain\Monster;

final readonly class MonsterDispatchOption
{
    public function __construct(
        public int $selector,
        public string $monsterDefinitionKey,
        public string $label,
        public int $costMoney,
        public bool $enabled,
        public int $rulesetVersionId,
    ) {}

    /** @return array{value: int, key: string, label: string, cost_money: int} */
    public function presentation(): array
    {
        return [
            'value' => $this->selector,
            'key' => $this->monsterDefinitionKey,
            'label' => $this->label,
            'cost_money' => $this->costMoney,
        ];
    }
}
