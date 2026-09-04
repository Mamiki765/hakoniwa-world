<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Ship;
use Illuminate\Database\Eloquent\Collection;

final class SurfaceVisibilityService
{
    public function __construct(private readonly SurfaceShipCatalog $ships) {}

    /**
     * @param  Collection<int, MapCell>  $chunkCells
     * @return array<string, true>
     */
    public function visibleCoordinates(
        MapSpace $mapSpace,
        Collection $chunkCells,
        ?int $viewerNationId,
    ): array {
        if ($viewerNationId === null || $chunkCells->isEmpty()) {
            return [];
        }

        $visible = [];
        $minX = (int) $chunkCells->min('x');
        $maxX = (int) $chunkCells->max('x');
        $minY = (int) $chunkCells->min('y');
        $maxY = (int) $chunkCells->max('y');
        $landSources = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $viewerNationId)
            ->whereBetween('x', [$minX - 1, $maxX + 1])
            ->whereBetween('y', [$minY - 1, $maxY + 1])
            ->whereHas('terrain', static fn ($query) => $query->where('is_water', false))
            ->get(['x', 'y']);
        foreach ($landSources as $cell) {
            $this->includeRadius($visible, new GridCoordinate((int) $cell->x, (int) $cell->y), 1);
        }

        $viewerShips = Ship::query()
            ->where('world_id', $mapSpace->world_id)
            ->where('nation_id', $viewerNationId)
            ->where('state', Ship::STATE_ACTIVE)
            ->whereHas('cell', static fn ($query) => $query->where('map_space_id', $mapSpace->id))
            ->with(['cell:id,map_space_id,x,y', 'rulesetVersion:id,settings'])
            ->orderBy('id')
            ->get();
        /** @var array<int, array<string, SurfaceShipDefinition>> $definitionsByRuleset */
        $definitionsByRuleset = [];
        foreach ($viewerShips as $ship) {
            if (! isset($definitionsByRuleset[$ship->ruleset_version_id])) {
                $definitionsByRuleset[$ship->ruleset_version_id] = collect(
                    $this->ships->definitions($ship->rulesetVersion->settings),
                )->keyBy('key')->all();
            }
            $definition = $definitionsByRuleset[$ship->ruleset_version_id][$ship->ship_type_key] ?? null;
            $cell = $ship->cell;
            if (! $definition instanceof SurfaceShipDefinition || ! $cell instanceof MapCell) {
                continue;
            }
            $this->includeRadius(
                $visible,
                new GridCoordinate((int) $cell->x, (int) $cell->y),
                $definition->visibilityRadius,
            );
        }

        return $visible;
    }

    /** @param array<string, true> $visible */
    private function includeRadius(array &$visible, GridCoordinate $source, int $radius): void
    {
        foreach ($source->radius($radius) as $coordinate) {
            $visible[$coordinate->x.':'.$coordinate->y] = true;
        }
    }
}
