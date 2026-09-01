<?php

namespace App\Domain\Underground\Facility;

use DomainException;

final class UndergroundCommandCatalog
{
    /** @return list<UndergroundCommandDefinition> */
    public function all(): array
    {
        $rows = config('underground-facilities.commands');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new DomainException('Underground facility command catalog is invalid.');
        }
        $definitions = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('Underground facility command definition is invalid.');
            }
            $definitions[] = new UndergroundCommandDefinition(
                key: $row['key'],
                name: $row['name'],
                description: $row['description'],
                cost_money: $row['cost_money'],
                action: $row['action'],
                facility_key: $row['facility_key'],
                effect: $row['effect'],
                sort_order: $row['sort_order'],
            );
        }

        return $definitions;
    }

    public function find(string $key): ?UndergroundCommandDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    public function get(string $key): UndergroundCommandDefinition
    {
        return $this->find($key) ?? throw new DomainException("Unknown Underground command {$key}.");
    }

    public function forFacility(string $facilityKey): UndergroundCommandDefinition
    {
        $matches = array_values(array_filter(
            $this->all(),
            static fn (UndergroundCommandDefinition $definition): bool => $definition->facility_key === $facilityKey,
        ));
        if (count($matches) !== 1) {
            throw new DomainException("Underground facility {$facilityKey} must have exactly one build command.");
        }

        return $matches[0];
    }
}
