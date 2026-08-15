<?php

namespace App\Domain\Turn;

use App\Models\MapCell;
use App\Models\Nation;
use App\Models\World;

final class TurnOrderService
{
    /** @return list<int> */
    public function stableNationIds(World $world): array
    {
        return Nation::query()
            ->where('world_id', $world->id)
            ->where('state', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function shuffledNationIds(World $world, TurnRandomStreamFactory $random): array
    {
        return $random
            ->stream(TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER)
            ->shuffle($this->stableNationIds($world));
    }

    /** @return list<int> */
    public function stableSurfaceCellIds(World $world): array
    {
        return MapCell::query()
            ->select('map_cells.id')
            ->join('map_spaces', 'map_spaces.id', '=', 'map_cells.map_space_id')
            ->where('map_spaces.world_id', $world->id)
            ->where('map_spaces.key', 'surface')
            ->orderBy('map_spaces.id')
            ->orderBy('map_cells.x')
            ->orderBy('map_cells.y')
            ->orderBy('map_cells.id')
            ->pluck('map_cells.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function shuffledSurfaceCellIds(World $world, TurnRandomStreamFactory $random): array
    {
        return $random
            ->stream(TurnRandomStreamFactory::SURFACE_CELL_ORDER)
            ->shuffle($this->stableSurfaceCellIds($world));
    }
}
