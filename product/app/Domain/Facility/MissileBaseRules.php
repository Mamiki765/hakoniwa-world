<?php

namespace App\Domain\Facility;

use App\Models\FacilityDefinition;
use DomainException;

final class MissileBaseRules
{
    public function initialExperience(FacilityDefinition $definition): int
    {
        return (int) ($definition->metadata['initial_experience'] ?? 0);
    }

    public function level(FacilityDefinition $definition, int $experience): int
    {
        $maximum = $this->maximumExperience($definition);
        if ($experience < 0 || $experience > $maximum) {
            throw new DomainException("Missile experience must be between 0 and {$maximum}.");
        }

        $level = 1;
        foreach ($definition->metadata['level_thresholds'] ?? [] as $threshold) {
            if ($experience >= (int) $threshold) {
                $level++;
            }
        }

        return $level;
    }

    public function maximumExperience(FacilityDefinition $definition): int
    {
        $maximum = $definition->metadata['maximum_experience'] ?? null;
        if (! is_int($maximum) || $maximum < 0) {
            throw new DomainException("Facility {$definition->key} does not define a valid experience maximum.");
        }

        return $maximum;
    }

    public function launchCapacity(FacilityDefinition $definition, int $experience): int
    {
        $level = $this->level($definition, $experience);
        $capacities = $definition->metadata['launch_capacity_by_level'] ?? [];

        return (int) ($capacities[$level] ?? $capacities[(string) $level] ?? $level);
    }
}
