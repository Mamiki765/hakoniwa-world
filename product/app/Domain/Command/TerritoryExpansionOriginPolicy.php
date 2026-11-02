<?php

namespace App\Domain\Command;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\OwnedLandProximity;
use App\Models\MapCell;
use App\Models\Nation;
use DomainException;

final class TerritoryExpansionOriginPolicy
{
    public function __construct(private readonly OwnedLandProximity $ownedLand) {}

    /** @param array<string, mixed> $metadata */
    public function within(Nation $nation, MapCell $target, array $metadata): bool
    {
        if ($this->requiresLand($metadata)) {
            return $this->ownedLand->within($nation, $target, 1, false);
        }

        // Frozen commands without a terrain selector retain their ownership-only origin.
        return MapCell::query()
            ->where('map_space_id', $target->map_space_id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($target): void {
                foreach ($this->neighbors($target) as $point) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $point->x)->where('y', $point->y));
                }
            })->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  callable(int, int): (array{terrain_key: string, owner_nation_id: int|null}|null)  $stateAt
     */
    public function withinProjected(Nation $nation, MapCell $target, array $metadata, callable $stateAt): bool
    {
        if ($this->requiresLand($metadata)) {
            return $this->ownedLand->withinProjected($nation, $target, 1, false, $stateAt);
        }
        foreach ($this->neighbors($target) as $point) {
            $state = $stateAt($point->x, $point->y);
            if ($state !== null && $state['owner_nation_id'] === $nation->id) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $metadata */
    private function requiresLand(array $metadata): bool
    {
        return match ($metadata['adjacency']['source_terrain'] ?? null) {
            null => false,
            'land' => true,
            default => throw new DomainException('Unsupported territory expansion origin terrain selector.'),
        };
    }

    /** @return list<GridCoordinate> */
    private function neighbors(MapCell $target): array
    {
        return array_values(array_filter(
            (new GridCoordinate($target->x, $target->y))->radius(1),
            static fn (GridCoordinate $point): bool => $point->x !== $target->x || $point->y !== $target->y,
        ));
    }
}
