<?php

namespace App\Domain\Map;

use App\Models\MapCell;
use App\Models\Nation;

final class OwnedLandProximity
{
    public function __construct(private readonly NationLandAreaCalculator $landArea) {}

    public function within(Nation $nation, MapCell $target, int $radius, bool $includeCenter = true): bool
    {
        $coordinates = (new GridCoordinate($target->x, $target->y))->radius($radius);
        if ($includeCenter && $target->owner_nation_id === $nation->id && $this->landArea->isLand($target)) {
            return true;
        }
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $target->x || $coordinate->y !== $target->y,
            ));
        }

        return $this->landArea->whereLand(MapCell::query()
            ->where('map_space_id', $target->map_space_id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            }))
            ->exists();
    }

    /** @param callable(int, int): (array{terrain_key: string, owner_nation_id: int|null}|null) $stateAt */
    public function withinProjected(Nation $nation, MapCell $target, int $radius, bool $includeCenter, callable $stateAt): bool
    {
        foreach ((new GridCoordinate($target->x, $target->y))->radius($radius) as $coordinate) {
            if (! $includeCenter && $coordinate->x === $target->x && $coordinate->y === $target->y) {
                continue;
            }
            $state = $stateAt($coordinate->x, $coordinate->y);
            if ($state !== null && $state['owner_nation_id'] === $nation->id
                && $this->landArea->isLandTerrain($state['terrain_key'])) {
                return true;
            }
        }

        return false;
    }
}
