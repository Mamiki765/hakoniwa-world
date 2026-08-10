<?php

namespace App\Domain\Command;

final class TerritoryInfluencePolicy
{
    /** @param array<string, mixed> $settings */
    public function enabled(array $settings): bool
    {
        return ($settings['enabled'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, true>  $activeNationIds
     */
    public function targetEligible(
        array $settings,
        ?int $ownerNationId,
        string $terrainKey,
        ?string $facilityKey,
        bool $monsterOccupied,
        bool $capitalCoreProtected,
        array $activeNationIds,
    ): bool {
        $target = $settings['target'] ?? null;
        if (! is_array($target) || $ownerNationId === null || ! isset($activeNationIds[$ownerNationId])) {
            return false;
        }
        if ($monsterOccupied || $capitalCoreProtected
            || in_array($terrainKey, $target['excluded_terrain_keys'] ?? [], true)
            || ($facilityKey !== null && in_array($facilityKey, $target['excluded_facility_keys'] ?? [], true))) {
            return false;
        }

        return $facilityKey === null
            ? in_array($terrainKey, $target['unfacilitated_terrain_keys'] ?? [], true)
            : in_array($facilityKey, $target['facility_keys'] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, true>  $activeNationIds
     */
    public function sourceEligible(
        array $settings,
        ?int $ownerNationId,
        int $targetOwnerNationId,
        string $terrainKey,
        ?string $facilityKey,
        bool $monsterOccupied,
        array $activeNationIds,
    ): bool {
        $source = $settings['source'] ?? null;

        return is_array($source)
            && $ownerNationId !== null
            && $ownerNationId !== $targetOwnerNationId
            && isset($activeNationIds[$ownerNationId])
            && ! $monsterOccupied
            && ! in_array($terrainKey, $source['excluded_terrain_keys'] ?? [], true)
            && ($facilityKey === null || ! in_array($facilityKey, $source['excluded_facility_keys'] ?? [], true));
    }
}
