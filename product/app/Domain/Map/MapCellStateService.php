<?php

namespace App\Domain\Map;

use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Facility\MissileBaseRules;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\TerrainDefinition;

final class MapCellStateService
{
    public function __construct(
        private readonly FacilityCapacityService $capacities,
        private readonly MissileBaseRules $missiles,
    ) {}

    public function transitionTerrain(MapCell $cell, TerrainDefinition $terrain): void
    {
        $cell->terrain_definition_id = $terrain->id;
        $cell->terrain_quantity = $terrain->quantity_key === null ? null : $terrain->initial_quantity;
        $cell->setRelation('terrain', $terrain);

        $facility = $cell->facility_definition_id === null
            ? null
            : FacilityDefinition::query()->find($cell->facility_definition_id);
        if ($facility !== null && ! in_array($terrain->key, $facility->buildable_terrain_keys, true)) {
            $this->setFacility($cell, null);
        }
    }

    public function setFacility(
        MapCell $cell,
        ?FacilityDefinition $facility,
        ?int $scale = null,
        ?int $experience = null,
    ): void {
        if ($facility === null) {
            $cell->facility_definition_id = null;
            $cell->monument_definition_id = null;
            $cell->facility_scale = null;
            $cell->facility_experience = null;
            $cell->facility_operational_state = null;
            $cell->setRelation('facility', null);

            return;
        }

        $cell->facility_definition_id = $facility->id;
        if ($facility->key !== 'monument') {
            $cell->monument_definition_id = null;
        }
        $cell->facility_operational_state = 'operational';
        $cell->setRelation('facility', $facility);

        if ($facility->scale_unit_people !== null) {
            $cell->facility_scale = $this->capacities->validateScale(
                $facility,
                $scale ?? $this->capacities->initialScale($facility),
            );
            $cell->facility_experience = null;

            return;
        }

        $cell->facility_scale = null;
        $cell->facility_experience = $facility->key === 'missile_base'
            ? ($experience ?? $this->missiles->initialExperience($facility))
            : null;
    }
}
