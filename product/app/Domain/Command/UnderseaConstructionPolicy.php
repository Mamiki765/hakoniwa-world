<?php

namespace App\Domain\Command;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\NationLandAreaCalculator;
use App\Models\MapCell;
use App\Models\Nation;

final class UnderseaConstructionPolicy
{
    public function __construct(private readonly NationLandAreaCalculator $landArea) {}

    public function appliesTo(string $commandKey): bool
    {
        return in_array($commandKey, ['build_seabed_base', 'build_undersea_city', 'build_undersea_fire_station'], true);
    }

    public function hasNearbyOwnedLand(Nation $nation, MapCell $target): bool
    {
        $coordinates = (new GridCoordinate($target->x, $target->y))->radius(3);

        return MapCell::query()
            ->where('map_space_id', $target->map_space_id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->with('terrain')
            ->get()
            ->contains(fn (MapCell $cell): bool => $this->landArea->isLand($cell));
    }
}
