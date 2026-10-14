<?php

namespace App\Domain\Map;

use App\Models\MapCell;
use App\Models\Nation;

final class OwnedLandProximity
{
    public function __construct(private readonly NationLandAreaCalculator $landArea) {}

    /** @param callable(MapCell): array{terrain_key: string, owner_nation_id: int|null}|null $project */
    public function within(Nation $nation, MapCell $target, int $radius, bool $includeCenter = true, ?callable $project = null): bool
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
            ->when($project === null, fn ($query) => $query->where('owner_nation_id', $nation->id))
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->with($project === null ? ['terrain'] : ['terrain', 'facility'])
            ->get()
            ->contains(function (MapCell $cell) use ($nation, $project): bool {
                if ($project === null) {
                    return $this->landArea->isLand($cell);
                }
                $state = $project($cell);

                return $state['owner_nation_id'] === $nation->id
                    && $this->landArea->isLandTerrain($state['terrain_key']);
            });
    }
}
