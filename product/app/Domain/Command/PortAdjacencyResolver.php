<?php

namespace App\Domain\Command;

use App\Domain\Map\GridCoordinate;
use App\Models\MapCell;
use App\Models\Nation;

final class PortAdjacencyResolver
{
    /**
     * @param  list<GridCoordinate>  $coordinates
     * @return array{owned_land: bool, sea: bool}
     */
    public function currentFacts(Nation $nation, int $mapSpaceId, array $coordinates): array
    {
        $neighbors = MapCell::query()
            ->where('map_space_id', $mapSpaceId)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->with('terrain')
            ->get();

        return [
            'owned_land' => $neighbors->contains(
                static fn (MapCell $neighbor): bool => $neighbor->owner_nation_id === $nation->id
                    && ! $neighbor->terrain->is_water,
            ),
            'sea' => $neighbors->contains(
                static fn (MapCell $neighbor): bool => $neighbor->terrain->key === 'sea',
            ),
        ];
    }
}
