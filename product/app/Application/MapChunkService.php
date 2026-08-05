<?php

namespace App\Application;

use App\Models\MapCell;
use App\Models\MapSpace;
use App\Services\MapCellPresenter;

final class MapChunkService
{
    public function __construct(private readonly MapCellPresenter $presenter) {}

    /** @return array<string, mixed> */
    public function present(MapSpace $mapSpace, int $chunkX, int $chunkY, ?int $viewerNationId): array
    {
        $chunk = $mapSpace->chunks()->where('chunk_x', $chunkX)->where('chunk_y', $chunkY)->first();
        $cells = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('chunk_x', $chunkX)
            ->where('chunk_y', $chunkY)
            ->with([
                'terrain',
                'facility',
                'monumentDefinition',
                'ownerNation:id,nation_number,name',
                'monsterOccupancy.monster.definition',
            ])
            ->orderBy('y')
            ->orderBy('x')
            ->get();

        $currentTurn = (int) $mapSpace->world()->value('current_turn');
        $presentedCells = $cells->map(fn (MapCell $cell): array => $this->presenter->present(
            $cell,
            $viewerNationId,
            $currentTurn,
        ))->values();
        $representationVersion = hash('sha256', json_encode($presentedCells, JSON_THROW_ON_ERROR));

        return [
            'world_id' => $mapSpace->world_id,
            'map_space_id' => $mapSpace->id,
            'chunk_x' => $chunkX,
            'chunk_y' => $chunkY,
            'chunk_size' => config('hakoniwa.ruleset.chunk_size'),
            'version' => $chunk === null ? 'empty' : $representationVersion,
            'state' => $chunk === null ? 'empty' : 'generated',
            'cells' => $presentedCells,
        ];
    }
}
