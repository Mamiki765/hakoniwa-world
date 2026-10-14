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
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $target->x || $coordinate->y !== $target->y,
            ));
        }

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
