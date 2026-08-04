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
        $resourceCapacities = $ruleset->settings['resource_capacities'] ?? [];
        $definitions = $ruleset->settings['resource_definitions'] ?? null;
        if (! is_array($resourceCapacities) || ! is_array($definitions)) {
            throw new DomainException('Published ruleset resource capacity settings are invalid.');
        }
        $definitionsByKey = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                throw new DomainException('Published ruleset resource definitions are invalid.');
            }
            $definitionsByKey[$definition['key']] = $definition;
        }
        foreach ($resourceCapacities as $resourceKey => $capacity) {
            $definition = $definitionsByKey[$resourceKey] ?? null;
            if (! is_string($resourceKey) || ! is_int($capacity) || $capacity < 0
                || ! is_array($definition) || ($definition['category'] ?? null) === 'food'
                || ($definition['storable'] ?? null) !== true) {
                throw new DomainException('Published ruleset resource capacity settings are invalid.');
            }
        }
        ksort($resourceCapacities);

        if ($this->modifiers !== []) {
            throw new DomainException('Capacity modifier semantics are deferred until E-04 is decided.');
        }

        return new NationCapacities($baseMoney, $baseFood, $resourceCapacities);
    }
}
