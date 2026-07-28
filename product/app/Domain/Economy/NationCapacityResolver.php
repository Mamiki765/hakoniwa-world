<?php

namespace App\Domain\Economy;

use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;

final class NationCapacityResolver
{
    /** @var list<CapacityModifier> */
    private array $modifiers;

    /** @param iterable<CapacityModifier> $modifiers */
    public function __construct(iterable $modifiers = [])
    {
        $this->modifiers = [...$modifiers];
    }

    public function resolve(Nation $nation, ?RulesetVersion $ruleset = null): NationCapacities
    {
        $world = $nation->world()->firstOrFail();
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
        if ($ruleset->id !== $world->ruleset_version_id) {
            throw new DomainException('Capacity ruleset does not match the Nation World snapshot.');
        }

        $baseMoney = $ruleset->settings['base_money_capacity'] ?? null;
        $baseFood = $ruleset->settings['base_food_capacity_tons'] ?? null;
        if (! is_int($baseMoney) || $baseMoney < 0 || ! is_int($baseFood) || $baseFood < 0) {
            throw new DomainException('Published ruleset capacity settings are invalid.');
        }

        $money = $baseMoney;
        $food = $baseFood;
        foreach ($this->modifiers as $modifier) {
            $money += $modifier->moneyCapacityDelta($nation);
            $food += $modifier->foodCapacityTonsDelta($nation);
        }
        if ($money < 0 || $food < 0) {
            throw new DomainException('Effective Nation capacity cannot be negative.');
        }

        return new NationCapacities($money, $food);
    }
}
