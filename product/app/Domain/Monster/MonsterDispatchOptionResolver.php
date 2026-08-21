<?php

namespace App\Domain\Monster;

use App\Models\CommandDefinition;
use DomainException;

final class MonsterDispatchOptionResolver
{
    public const CATALOG = 'monster_dispatch_options';

    public const OPTIONS_METADATA_KEY = 'monster_dispatch_options';

    /**
     * @return list<MonsterDispatchOption>
     */
    public function options(CommandDefinition $definition): array
    {
        if (($definition->metadata['quantity_selects_catalog'] ?? null) !== self::CATALOG) {
            return [];
        }

        return array_map(
            fn (array $option): MonsterDispatchOption => new MonsterDispatchOption(
                selector: $option['value'],
                monsterDefinitionKey: $option['monster_key'],
                label: $option['label'],
                costMoney: $option['cost_money'],
                enabled: $option['enabled'],
                rulesetVersionId: (int) $definition->ruleset_version_id,
            ),
            $this->validateMetadata($definition->metadata),
        );
    }

    public function resolve(CommandDefinition $definition, int $selector): MonsterDispatchOption
    {
        foreach ($this->options($definition) as $option) {
            if ($option->selector === $selector && $option->enabled) {
                return $option;
            }
        }

        throw new DomainException('Selected monster dispatch option is unavailable.');
    }

    public function defaultSelector(CommandDefinition $definition): ?int
    {
        if (($definition->metadata['quantity_selects_catalog'] ?? null) !== self::CATALOG) {
            return null;
        }

        $this->validateMetadata($definition->metadata);

        return $definition->metadata['default_selector_value'];
    }

    /**
     * Validate the deliberately narrow v11 catalog without introducing a generic pricing engine.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<array{value: int, monster_key: string, label: string, cost_money: int, enabled: bool}>
     */
    public function validateMetadata(array $metadata): array
    {
        $allowed = ['parameters', 'private_command', 'quantity_selects_catalog', 'default_selector_value', self::OPTIONS_METADATA_KEY];
        $unknown = array_values(array_diff(array_keys($metadata), $allowed));
        if ($unknown !== []) {
            throw new DomainException('monster_dispatch metadata contains unknown fields: '.implode(', ', $unknown).'.');
        }
        if (($metadata['quantity_selects_catalog'] ?? null) !== self::CATALOG
            || ($metadata['default_selector_value'] ?? null) !== 1
            || ($metadata['private_command'] ?? null) !== true) {
            throw new DomainException('monster_dispatch selector metadata differs from the approved v11 contract.');
        }
        $options = $metadata[self::OPTIONS_METADATA_KEY] ?? null;
        if (! is_array($options) || ! array_is_list($options)) {
            throw new DomainException('monster_dispatch options must be an authored list.');
        }
        $expected = [
            ['value' => 1, 'monster_key' => 'mecha_inora', 'label' => 'メカいのら', 'cost_money' => 3_000, 'enabled' => true],
            ['value' => 2, 'monster_key' => 'mecha_inora_zero', 'label' => 'メカいのら零式', 'cost_money' => 9_999, 'enabled' => true],
        ];
        if ($this->canonicalize($options) !== $this->canonicalize($expected)) {
            throw new DomainException('monster_dispatch options differ from the approved v11 two-option contract.');
        }

        return $options;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
