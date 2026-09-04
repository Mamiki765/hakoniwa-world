<?php

namespace App\Domain\Ship;

use App\Models\CommandDefinition;
use App\Models\RulesetVersion;
use DomainException;

final class SurfaceShipCatalog
{
    public const CATALOG = 'surface_ship_definitions';

    /** @var array<int, list<SurfaceShipDefinition>> */
    private array $optionsByRulesetId = [];

    /**
     * @param  array<string, mixed>  $settings
     * @return list<SurfaceShipDefinition>
     */
    public function definitions(array $settings): array
    {
        $section = $settings['surface_ships'] ?? null;
        $definitions = is_array($section) ? ($section['definitions'] ?? null) : null;
        if (! is_array($definitions) || array_is_list($definitions)) {
            throw new DomainException('Surface Ship definitions are unavailable.');
        }

        $result = [];
        foreach ($definitions as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_array($value)) {
                throw new DomainException('Surface Ship definition is malformed.');
            }
            $result[] = new SurfaceShipDefinition(
                selector: $this->positiveInteger($value, 'build_selector'),
                key: $key,
                name: $this->nonEmptyString($value, 'name'),
                assetKey: $this->nonEmptyString($value, 'asset_key'),
                sortOrder: $this->positiveInteger($value, 'sort_order'),
                buildCostMoney: $this->positiveInteger($value, 'build_cost_money'),
                maximumHp: $this->positiveInteger($value, 'maximum_hp'),
                movementOilUnits: $this->positiveInteger($value, 'movement_oil_units'),
                movementRewardResourceKey: $this->nullableString($value, 'movement_reward_resource_key'),
                movementRewardResourceUnits: $this->nonNegativeInteger($value, 'movement_reward_resource_units'),
                movementRewardMoney: $this->nonNegativeInteger($value, 'movement_reward_money'),
                visibilityRadius: $this->positiveInteger($value, 'visibility_radius'),
            );
        }

        usort($result, static fn (SurfaceShipDefinition $left, SurfaceShipDefinition $right): int => [$left->sortOrder, $left->selector, $left->key] <=> [$right->sortOrder, $right->selector, $right->key]
        );
        if (count(array_unique(array_map(
            static fn (SurfaceShipDefinition $definition): int => $definition->selector,
            $result,
        ))) !== count($result)) {
            throw new DomainException('Surface Ship build selectors must be unique.');
        }

        return $result;
    }

    /** @return list<SurfaceShipDefinition> */
    public function options(CommandDefinition $command): array
    {
        if (($command->metadata['quantity_selects_catalog'] ?? null) !== self::CATALOG) {
            return [];
        }

        return $this->optionsByRulesetId[$command->ruleset_version_id]
            ??= $this->definitions($this->settings($command));
    }

    public function resolve(CommandDefinition $command, int $selector): SurfaceShipDefinition
    {
        foreach ($this->options($command) as $definition) {
            if ($definition->selector === $selector) {
                return $definition;
            }
        }

        throw new DomainException('Selected Surface Ship definition is unavailable.');
    }

    public function defaultSelector(CommandDefinition $command): ?int
    {
        if (($command->metadata['quantity_selects_catalog'] ?? null) !== self::CATALOG) {
            return null;
        }
        $selector = $command->metadata['default_selector_value'] ?? null;
        if (! is_int($selector)) {
            throw new DomainException('Surface Ship default selector is malformed.');
        }
        $this->resolve($command, $selector);

        return $selector;
    }

    /** @param array<string, mixed> $settings */
    public function capacityPerType(array $settings): int
    {
        $section = $settings['surface_ships'] ?? null;
        $capacity = is_array($section) ? ($section['capacity_per_type'] ?? null) : null;
        if (! is_int($capacity) || $capacity < 1) {
            throw new DomainException('Surface Ship capacity is malformed.');
        }

        return $capacity;
    }

    /** @return array<string, mixed> */
    private function settings(CommandDefinition $command): array
    {
        $ruleset = $command->relationLoaded('rulesetVersion')
            ? $command->rulesetVersion
            : $command->rulesetVersion()->first();
        $settings = $ruleset instanceof RulesetVersion ? $ruleset->settings : null;
        if (! is_array($settings)) {
            throw new DomainException('Surface Ship command Ruleset settings are unavailable.');
        }

        return $settings;
    }

    /** @param array<string, mixed> $definition */
    private function positiveInteger(array $definition, string $field): int
    {
        $value = $definition[$field] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Surface Ship {$field} must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function nonNegativeInteger(array $definition, string $field): int
    {
        $value = $definition[$field] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new DomainException("Surface Ship {$field} must be a non-negative integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function nonEmptyString(array $definition, string $field): string
    {
        $value = $definition[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new DomainException("Surface Ship {$field} must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function nullableString(array $definition, string $field): ?string
    {
        $value = $definition[$field] ?? null;
        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new DomainException("Surface Ship {$field} must be null or a non-empty string.");
        }

        return $value;
    }
}
