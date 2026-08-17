<?php

namespace App\Application;

use App\Models\MapCell;
use App\Models\TerrainDefinition;
use DomainException;

final class DisasterMutableCellIndex
{
    /** @var array<string, MapCell> */
    private array $cellsByCoordinate = [];

    /**
     * @param  array<int, true>  $activeNationIds
     * @param  array<string, TerrainDefinition>  $terrainDefinitions
     */
    private function __construct(
        private array $activeNationIds,
        private array $terrainDefinitions,
    ) {}

    /**
     * @param  iterable<int, MapCell>  $cells
     * @param  list<int>  $activeNationIds
     * @param  iterable<array-key, TerrainDefinition>  $terrainDefinitions
     */
    public static function fromCells(
        iterable $cells,
        array $activeNationIds = [],
        iterable $terrainDefinitions = [],
    ): self {
        $terrainByKey = [];
        foreach ($terrainDefinitions as $key => $definition) {
            $terrainByKey[is_string($key) ? $key : $definition->key] = $definition;
        }
        $index = new self(array_fill_keys($activeNationIds, true), $terrainByKey);
        $index->addCells($cells);

        return $index;
    }

    /** @return list<MapCell> */
    public function cells(): array
    {
        $cells = array_values($this->cellsByCoordinate);
        usort($cells, static fn (MapCell $left, MapCell $right): int => $left->id <=> $right->id);

        return $cells;
    }

    /** @param iterable<int, MapCell> $cells */
    public function addCells(iterable $cells): void
    {
        foreach ($cells as $cell) {
            $key = self::coordinateKey($cell->x, $cell->y);
            if (! isset($this->cellsByCoordinate[$key])) {
                $this->cellsByCoordinate[$key] = $cell;
            }
        }
    }

    public function cellAt(int $x, int $y): ?MapCell
    {
        return $this->cellsByCoordinate[self::coordinateKey($x, $y)] ?? null;
    }

    public function isMutable(MapCell $cell): bool
    {
        return $cell->owner_nation_id === null || isset($this->activeNationIds[$cell->owner_nation_id]);
    }

    public function terrainDefinition(string $key): TerrainDefinition
    {
        return $this->terrainDefinitions[$key]
            ?? throw new DomainException("Terrain definition {$key} is missing from the disaster phase catalog.");
    }

    private static function coordinateKey(int $x, int $y): string
    {
        return $x.':'.$y;
    }
}
