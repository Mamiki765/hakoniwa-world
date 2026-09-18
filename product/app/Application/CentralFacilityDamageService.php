<?php

namespace App\Application;

use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\TerrainDefinition;
use DomainException;

final readonly class CentralFacilityDamageService
{
    public function __construct(
        private MapCellStateService $cells,
        private TurnEventRecorder $events,
    ) {}

    public function isCentral(TurnContext $context, MapCell $cell): bool
    {
        $contract = $this->contract($context);

        return $contract !== null
            && in_array($cell->facility?->key, $contract['facility_keys'], true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{facility_key: string, before_scale: int, after_scale: int, scale_loss: int, destroyed: bool, to_terrain_key: string}|null
     */
    public function apply(
        TurnContext $context,
        MapCell $cell,
        int $levelLoss,
        string $damageKind,
        string $sourceKey,
        array $metadata = [],
    ): ?array {
        $contract = $this->contract($context);
        $facilityKey = $cell->facility?->key;
        if ($contract === null || ! in_array($facilityKey, $contract['facility_keys'], true)) {
            return null;
        }
        $before = $cell->facility_scale;
        if (! is_int($before) || $before < 1 || $before > 90) {
            throw new DomainException('A central facility has invalid persisted level data.');
        }
        if ($levelLoss < 1) {
            throw new DomainException('Central-facility level damage must be positive.');
        }

        $ownerNationId = $cell->owner_nation_id;
        $ownerNationName = $cell->ownerNation?->name;
        $after = max(0, $before - $levelLoss);
        $appliedLoss = $before - $after;
        $destroyed = $after === 0;
        if ($destroyed) {
            $this->cells->setFacility($cell, null);
            $terrain = TerrainDefinition::query()
                ->where('key', $contract['destroyed_terrain_key'])
                ->firstOrFail();
            $this->cells->transitionTerrain($cell, $terrain);
            $cell->owner_nation_id = null;
            $cell->setRelation('ownerNation', null);
            $cell->population = 0;
        } else {
            $cell->facility_scale = $after;
        }
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'facility.partially_damaged', $cell, [
            'nation_id' => $ownerNationId,
            'nation_name' => $ownerNationName,
            'x' => $cell->x,
            'y' => $cell->y,
            'damage_kind' => $damageKind,
            'source_key' => $sourceKey,
            'facility_key' => $facilityKey,
            'before_scale' => $before,
            'after_scale' => $after,
            'scale_loss' => $appliedLoss,
            'facility_destroyed' => $destroyed,
            ...$metadata,
        ], 'private');

        return [
            'facility_key' => $facilityKey,
            'before_scale' => $before,
            'after_scale' => $after,
            'scale_loss' => $appliedLoss,
            'destroyed' => $destroyed,
            'to_terrain_key' => $cell->terrain->key,
        ];
    }

    /** @return array{facility_keys: list<string>, destroyed_terrain_key: string}|null */
    private function contract(TurnContext $context): ?array
    {
        $contract = $context->ruleset->settings['central_facilities']['damage_behavior'] ?? null;
        if ($contract === null) {
            return null;
        }
        $facilityKeys = is_array($contract) ? ($contract['facility_keys'] ?? null) : null;
        if (! is_array($contract)
            || ! is_array($facilityKeys)
            || ! array_is_list($facilityKeys)
            || $facilityKeys === []
            || array_filter($facilityKeys, static fn (mixed $key): bool => ! is_string($key) || $key === '') !== []
            || ! is_string($contract['destroyed_terrain_key'] ?? null)
            || $contract['destroyed_terrain_key'] === '') {
            throw new DomainException('The active Ruleset has an invalid central-facility damage contract.');
        }

        return $contract;
    }
}
