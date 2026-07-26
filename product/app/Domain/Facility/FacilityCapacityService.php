<?php

namespace App\Domain\Facility;

use App\Models\FacilityDefinition;
use DomainException;

final class FacilityCapacityService
{
    public function initialScale(FacilityDefinition $definition): int
    {
        if ($definition->initial_scale === null) {
            throw new DomainException("{$definition->key} does not use facility scale.");
        }

        return $this->validateScale($definition, $definition->initial_scale);
    }

    public function validateScale(FacilityDefinition $definition, int $scale): int
    {
        if ($definition->scale_unit_people === null || $definition->maximum_scale === null) {
            throw new DomainException("{$definition->key} does not use facility scale.");
        }
        if ($scale < 0 || $scale > $definition->maximum_scale) {
            throw new DomainException("Facility scale must be between 0 and {$definition->maximum_scale}.");
        }

        return $scale;
    }

    public function capacityPeople(FacilityDefinition $definition, int $scale): int
    {
        $this->validateScale($definition, $scale);

        return $scale * (int) $definition->scale_unit_people;
    }

    /** @return array{facility_scale: int, capacity_people: int, scale_unit_people: int, initial_scale: int, scale_increment: int, maximum_scale: int} */
    public function describe(FacilityDefinition $definition, int $scale): array
    {
        return [
            'facility_scale' => $this->validateScale($definition, $scale),
            'capacity_people' => $this->capacityPeople($definition, $scale),
            'scale_unit_people' => (int) $definition->scale_unit_people,
            'initial_scale' => (int) $definition->initial_scale,
            'scale_increment' => (int) $definition->scale_increment,
            'maximum_scale' => (int) $definition->maximum_scale,
        ];
    }
}
