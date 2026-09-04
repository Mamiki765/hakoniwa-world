<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCapital;
use App\Services\MapCellPresenter;
use DomainException;

final class MapChunkService
{
    public function __construct(
        private readonly MapCellPresenter $presenter,
        private readonly SurfaceVisibilityService $visibility,
    ) {}

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
                'ship.nation:id,nation_number,name',
                'ship.rulesetVersion:id,settings',
                'monsterOccupancy.monster.definition',
            ])
            ->orderBy('y')
            ->orderBy('x')
            ->get();

        if ($chunk !== null) {
            $expected = $mapSpace->currentBounds()->cellCountWithinChunk($chunkX, $chunkY);
            if ($expected === 0 || $cells->count() !== $expected) {
                throw new DomainException(
                    "MapSpace {$mapSpace->id} chunk ({$chunkX}, {$chunkY}) violates completed current-bounds coverage.",
                );
            }
        } elseif ($cells->isNotEmpty()) {
            throw new DomainException(
                "MapSpace {$mapSpace->id} chunk ({$chunkX}, {$chunkY}) has cells without completion metadata.",
            );
        }

        $currentTurn = (int) $mapSpace->world()->value('current_turn');
        $lifecycle = config('hakoniwa.ruleset.nation_lifecycle', []);
        $radius = is_int($lifecycle['dormant_protection_radius'] ?? null)
            ? $lifecycle['dormant_protection_radius'] : 0;
        $theme = is_string($lifecycle['dormant_visual_theme'] ?? null)
            ? $lifecycle['dormant_visual_theme'] : null;
        $visibleCoordinates = $this->visibility->visibleCoordinates($mapSpace, $cells, $viewerNationId);
        $dormantCapitals = NationCapital::query()
            ->join('nations', 'nations.id', '=', 'nation_capitals.nation_id')
            ->where('nations.world_id', $mapSpace->world_id)->where('nations.state', 'dormant')
            ->orderBy('nation_capitals.nation_id')->get(['nation_capitals.x', 'nation_capitals.y']);
        $presentedCells = $cells->map(fn (MapCell $cell): array => $this->presenter->present(
            $cell,
            $viewerNationId,
            $currentTurn,
            $dormantCapitals->contains(fn (NationCapital $capital): bool => (new GridCoordinate($capital->x, $capital->y))
                ->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= $radius)
                ? $theme : null,
            isset($visibleCoordinates[$cell->x.':'.$cell->y]),
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
