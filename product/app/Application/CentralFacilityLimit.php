<?php

namespace App\Application;

use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use DomainException;

final class CentralFacilityLimit
{
    /** @param array<string, mixed> $rulesetSettings */
    public function reachedMaximum(
        array $rulesetSettings,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $target,
    ): ?int {
        $facilityKey = $definition->result_facility_key;
        $contract = is_string($facilityKey)
            ? ($rulesetSettings['central_facilities']['definitions'][$facilityKey] ?? null)
            : null;
        if (! is_array($contract)) {
            return null;
        }
        $maximum = $contract['maximum_per_nation'] ?? null;
        if (! is_int($maximum) || $maximum < 1) {
            throw new DomainException("Central facility {$facilityKey} has an invalid Nation limit.");
        }
        $existing = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->where('id', '<>', $target->id)
            ->whereHas('facility', fn ($query) => $query->where('key', $facilityKey))
            ->count();

        return $existing >= $maximum ? $maximum : null;
    }
}
