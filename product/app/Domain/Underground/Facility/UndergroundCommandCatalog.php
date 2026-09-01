<?php

namespace App\Domain\Underground\Facility;

use DomainException;

final class UndergroundCommandCatalog
{
    private const SECTION = 'underground_facility_development';

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @return list<UndergroundCommandDefinition>
     */
    public function all(array $rulesetSettings): array
    {
        $section = $rulesetSettings[self::SECTION] ?? null;
        if ($section === null) {
            return [];
        }
        $rows = is_array($section) ? ($section['command_definitions'] ?? null) : null;
        $facilities = is_array($section) ? ($section['facility_definitions'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new DomainException('Underground facility command catalog is invalid.');
        }
        if (! is_array($facilities) || array_is_list($facilities)) {
            throw new DomainException('Underground facility definition catalog is invalid.');
        }
        $definitions = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('Underground facility command definition is invalid.');
            }
            $facility = is_string($row['facility_key'] ?? null)
                ? ($facilities[$row['facility_key']] ?? null)
                : null;
            $effect = $facility === null ? [] : ($facility['effect'] ?? null);
            $metadata = $row['metadata'] ?? null;
            if (! is_array($effect) || ! is_array($metadata)) {
                throw new DomainException('Underground facility command definition is invalid.');
            }
            $definitions[] = new UndergroundCommandDefinition(
                key: $row['key'],
                name: $row['name'],
                description: $row['description'],
                cost_money: $row['cost_money'],
                action: $row['action'],
                facility_key: $row['facility_key'],
                effect: $effect,
                sort_order: $row['sort_order'],
                target_type: $row['target_type'],
                execution_phase: $row['execution_phase'],
                consumes_turn: $metadata['consumes_turn'],
                quantity_semantics: $metadata['quantity_semantics'],
            );
        }

        return $definitions;
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function find(array $rulesetSettings, string $key): ?UndergroundCommandDefinition
    {
        foreach ($this->all($rulesetSettings) as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function get(array $rulesetSettings, string $key): UndergroundCommandDefinition
    {
        return $this->find($rulesetSettings, $key)
            ?? throw new DomainException("Unknown Underground command {$key}.");
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function forFacility(array $rulesetSettings, string $facilityKey): UndergroundCommandDefinition
    {
        $matches = array_values(array_filter(
            $this->all($rulesetSettings),
            static fn (UndergroundCommandDefinition $definition): bool => $definition->facility_key === $facilityKey,
        ));
        if (count($matches) !== 1) {
            throw new DomainException("Underground facility {$facilityKey} must have exactly one build command.");
        }

        return $matches[0];
    }
}
