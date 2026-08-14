<?php

namespace App\Domain\Map;

use App\Models\MapCell;
use App\Models\Nation;
use App\Models\World;
use Illuminate\Database\Eloquent\Collection;

final class NationLandAreaCalculator
{
    /** @var list<string> */
    private const EXCLUDED_TERRAIN_KEYS = ['sea', 'shallow'];

    public function forNation(Nation $nation): int
    {
        return $this->byNation($this->surfaceCells($nation->world_id))[$nation->id] ?? 0;
    }

    /** @return array<int, int> */
    public function forWorld(World $world): array
    {
        return $this->byNation($this->surfaceCells($world->id));
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, int>
     */
    public function forNationIds(World $world, array $nationIds): array
    {
        if ($nationIds === []) {
            return [];
        }
        $rows = MapCell::query()
            ->selectRaw('map_cells.owner_nation_id, COUNT(*) AS aggregate')
            ->join('map_spaces', 'map_spaces.id', '=', 'map_cells.map_space_id')
            ->where('map_spaces.world_id', $world->id)
            ->where('map_spaces.key', 'surface')
            ->whereIn('map_cells.owner_nation_id', $nationIds)
            ->whereHas('terrain', fn ($query) => $query->whereNotIn('key', self::EXCLUDED_TERRAIN_KEYS))
            ->groupBy('map_cells.owner_nation_id')
            ->pluck('aggregate', 'map_cells.owner_nation_id');
        $counts = [];
        foreach ($rows as $nationId => $count) {
            $counts[(int) $nationId] = (int) $count;
        }
        ksort($counts, SORT_NUMERIC);

        return $counts;
    }

    /**
     * @param  iterable<int, MapCell>  $cells
     * @return array<int, int>
     */
    public function byNation(iterable $cells): array
    {
        $counts = [];
        foreach ($cells as $cell) {
            if ($cell->owner_nation_id === null || ! $this->isLand($cell)) {
                continue;
            }
            $counts[$cell->owner_nation_id] = ($counts[$cell->owner_nation_id] ?? 0) + 1;
        }

        ksort($counts, SORT_NUMERIC);

        return $counts;
    }

    public function isLand(MapCell $cell): bool
    {
        return ! in_array($cell->terrain->key, self::EXCLUDED_TERRAIN_KEYS, true);
    }

    /** @return Collection<int, MapCell> */
    private function surfaceCells(int $worldId): Collection
    {
        return MapCell::query()
            ->select('map_cells.*')
            ->join('map_spaces', 'map_spaces.id', '=', 'map_cells.map_space_id')
            ->where('map_spaces.world_id', $worldId)
            ->where('map_spaces.key', 'surface')
            ->whereNotNull('map_cells.owner_nation_id')
            ->with('terrain')
            ->orderBy('map_cells.id')
            ->get();
    }
}
