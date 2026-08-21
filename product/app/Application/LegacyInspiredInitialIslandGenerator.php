<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\World\DeterministicRandom;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LegacyInspiredInitialIslandGenerator implements InitialIslandGenerator
{
    /** @var list<string> */
    private const PLANNED_CELL_FIELDS = [
        'map_space_id', 'map_chunk_id', 'x', 'y',
        'terrain_definition_id', 'facility_definition_id', 'monument_definition_id',
        'owner_nation_id', 'population', 'terrain_quantity', 'facility_scale',
        'facility_experience', 'facility_operational_state', 'state', 'version',
    ];

    public function generate(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): NationCapital
    {
        return $this->apply($this->plan($mapSpace, $nation, $center, $seed), $mapSpace, $nation);
    }

    public function plan(MapSpace $mapSpace, Nation $nation, GridCoordinate $center, string $seed): InitialIslandPlan
    {
        $ruleset = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail();
        $rules = $ruleset->settings;
        $random = new DeterministicRandom($seed);
        $reservation = $center->radius($rules['initial_island_reservation_radius']);
        $terrainIds = TerrainDefinition::query()->pluck('id', 'key');
        $lockedCells = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where(function ($query) use ($reservation): void {
                foreach ($reservation as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MapCell $cell): string => $cell->x.':'.$cell->y);

        $cells = $lockedCells->map(static fn (MapCell $cell): MapCell => clone $cell);

        if ($cells->count() !== count($reservation)) {
            throw new DomainException('初期島の予約範囲が生成済み世界からはみ出しています。');
        }

        foreach ($cells as $cell) {
            if ((int) $cell->terrain_definition_id !== (int) $terrainIds['sea']
                || $cell->owner_nation_id !== null
                || $cell->facility_definition_id !== null) {
                throw new DomainException('選択された海域はすでに使用されています。');
            }
        }

        $facilityIds = FacilityDefinition::query()->pluck('id', 'key');
        $territoryRadius = $rules['initial_territory_radius'];

        foreach ($center->radius($rules['initial_island_land_radius']) as $coordinate) {
            $cell = $this->cell($cells, $coordinate);
            $cell->terrain_definition_id = $terrainIds['wasteland'];
            $cell->owner_nation_id = $coordinate->distanceTo($center) <= $territoryRadius ? $nation->id : null;
        }

        $growthArea = $center->radius($rules['initial_island_growth_radius']);
        for ($step = 0; $step < $rules['initial_island_growth_steps']; $step++) {
            $coordinate = $growthArea[$random->nextInt(count($growthArea))];
            $cell = $this->cell($cells, $coordinate);
            $landNeighbors = 0;
            for ($direction = 0; $direction < 6; $direction++) {
                $neighbor = $cells->get($this->key($coordinate->neighbor($direction)));
                if ($neighbor instanceof MapCell
                    && ! in_array(
                        (int) $neighbor->terrain_definition_id,
                        [(int) $terrainIds['sea'], (int) $terrainIds['shallow']],
                        true,
                    )) {
                    $landNeighbors++;
                }
            }
            if ($landNeighbors === 0) {
                continue;
            }
            if ((int) $cell->terrain_definition_id === (int) $terrainIds['wasteland']) {
                $cell->terrain_definition_id = $terrainIds['plain'];
            } elseif ((int) $cell->terrain_definition_id === (int) $terrainIds['shallow']) {
                $cell->terrain_definition_id = $terrainIds['wasteland'];
            } elseif ((int) $cell->terrain_definition_id === (int) $terrainIds['sea']) {
                $cell->terrain_definition_id = $terrainIds['shallow'];
            }
        }

        $placementCells = array_values(array_filter(
            $random->shuffled($center->radius(2)),
            fn (GridCoordinate $coordinate): bool => $coordinate->distanceTo($center) > 0,
        ));
        $cursor = 0;
        for ($index = 0; $index < 3; $index++) {
            $cell = $this->cell($cells, $placementCells[$cursor++]);
            $cell->terrain_definition_id = $terrainIds['forest'];
            $cell->facility_definition_id = null;
            $cell->population = 0;
            $cell->terrain_quantity = $rules['terrain_quantities']['forest']['initial_quantity'];
            $cell->facility_scale = null;
            $cell->facility_experience = null;
            $cell->facility_operational_state = null;
        }

        $village = $this->cell($cells, $placementCells[$cursor++]);
        $village->terrain_definition_id = $terrainIds['plain'];
        $village->facility_definition_id = $facilityIds['village'];
        $village->population = 500;
        $village->terrain_quantity = null;
        $village->facility_scale = null;
        $village->facility_experience = null;
        $village->facility_operational_state = 'operational';

        $mountain = $this->cell($cells, $placementCells[$cursor++]);
        $mountain->terrain_definition_id = $terrainIds['mountain'];
        $mountain->facility_definition_id = null;
        $mountain->population = 0;
        $mountain->terrain_quantity = null;
        $mountain->facility_scale = null;
        $mountain->facility_experience = null;
        $mountain->facility_operational_state = null;

        $base = $this->cell($cells, $placementCells[$cursor++]);
        $base->terrain_definition_id = $terrainIds['plain'];
        $base->facility_definition_id = $facilityIds['missile_base'];
        $base->population = 0;
        $base->terrain_quantity = null;
        $base->facility_scale = null;
        $base->facility_experience = $rules['facility_definitions']['missile_base']['initial_experience'];
        $base->facility_operational_state = 'operational';

        $starterPlain = $this->cell($cells, $placementCells[$cursor]);
        $starterPlain->terrain_definition_id = $terrainIds['plain'];
        $starterPlain->facility_definition_id = null;
        $starterPlain->population = 0;
        $starterPlain->terrain_quantity = null;
        $starterPlain->facility_scale = null;
        $starterPlain->facility_experience = null;
        $starterPlain->facility_operational_state = null;

        $capitalCell = $this->cell($cells, $center);
        $capitalCell->terrain_definition_id = $terrainIds['plain'];
        $capitalCell->facility_definition_id = $facilityIds['capital'];
        $capitalCell->owner_nation_id = $nation->id;
        $capitalCell->population = $rules['capital_initial_population'];
        $capitalCell->terrain_quantity = null;
        $capitalCell->facility_scale = null;
        $capitalCell->facility_experience = null;
        $capitalCell->facility_operational_state = 'operational';

        $this->ensureMinimumShallows(
            $cells,
            $reservation,
            (int) $terrainIds['sea'],
            (int) $terrainIds['shallow'],
            (int) ($rules['initial_island_minimum_shallow_cells'] ?? 0),
            $random,
        );

        $changedChunks = [];
        $cellPrestates = [];
        $cellWrites = [];
        foreach ($cells as $cell) {
            if ($cell->isDirty()) {
                $cell->version++;
                $changedChunks[$cell->map_chunk_id] = true;
                /** @var MapCell $lockedCell */
                $lockedCell = $lockedCells->get($cell->x.':'.$cell->y);
                $cellPrestates[$cell->id] = $this->plannedAttributes($lockedCell);
                $cellWrites[$cell->id] = $this->plannedAttributes($cell);
            }
        }
        ksort($cellWrites, SORT_NUMERIC);
        $changedCellIds = array_map(static fn ($id): int => (int) $id, array_keys($cellWrites));
        $changedChunkIds = array_map(static fn ($id): int => (int) $id, array_keys($changedChunks));
        sort($changedChunkIds, SORT_NUMERIC);

        return new InitialIslandPlan(
            mapSpaceId: $mapSpace->id,
            nationId: $nation->id,
            rulesetVersionId: $ruleset->id,
            centerX: $center->x,
            centerY: $center->y,
            seed: $seed,
            changedCellIds: $changedCellIds,
            cellPrestates: $cellPrestates,
            cellWrites: $cellWrites,
            changedChunkIds: $changedChunkIds,
            capitalCellId: $capitalCell->id,
        );
    }

    public function apply(InitialIslandPlan $plan, MapSpace $mapSpace, Nation $nation): NationCapital
    {
        $rulesetId = (int) $nation->world()->firstOrFail()->ruleset_version_id;
        if ($plan->mapSpaceId !== $mapSpace->id || $plan->nationId !== $nation->id
            || $plan->rulesetVersionId !== $rulesetId) {
            throw new DomainException('Initial-island plan does not match the locked Nation and ruleset.');
        }
        $cells = MapCell::query()->whereIn('id', $plan->changedCellIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($cells->count() !== count($plan->changedCellIds)) {
            throw new DomainException('Initial-island plan references missing changed cells.');
        }
        foreach ($plan->changedCellIds as $cellId) {
            /** @var MapCell $cell */
            $cell = $cells->get($cellId);
            if ($this->plannedAttributes($cell) !== $plan->cellPrestates[$cellId]) {
                throw new DomainException('Initial-island changed-cell prestate no longer matches its immutable plan.');
            }
            $cell->forceFill($plan->cellWrites[$cellId]);
            $cell->save();
        }
        DB::table('map_chunks')->whereIn('id', $plan->changedChunkIds)->increment('version');
        $capital = NationCapital::query()->create([
            'nation_id' => $nation->id,
            'map_cell_id' => $plan->capitalCellId,
            'x' => $plan->centerX,
            'y' => $plan->centerY,
        ]);
        DB::table('world_generation_runs')->insert([
            'map_space_id' => $mapSpace->id,
            'generator_id' => config('hakoniwa.initial_island.generator_id'),
            'generator_version' => config('hakoniwa.initial_island.generator_version'),
            'seed' => $plan->seed,
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $capital;
    }

    /** @return array<string, int|string|null> */
    private function plannedAttributes(MapCell $cell): array
    {
        $attributes = [];
        foreach (self::PLANNED_CELL_FIELDS as $field) {
            $value = $cell->getAttribute($field);
            $attributes[$field] = is_int($value) || is_string($value) || $value === null
                ? $value
                : (int) $value;
        }

        return $attributes;
    }

    /** @param Collection<string, MapCell> $cells */
    private function cell(Collection $cells, GridCoordinate $coordinate): MapCell
    {
        $cell = $cells->get($this->key($coordinate));
        if (! $cell instanceof MapCell) {
            throw new DomainException('初期島生成に必要なセルがありません。');
        }

        return $cell;
    }

    private function key(GridCoordinate $coordinate): string
    {
        return $coordinate->x.':'.$coordinate->y;
    }

    /**
     * @param  Collection<string, MapCell>  $cells
     * @param  list<GridCoordinate>  $reservation
     */
    private function ensureMinimumShallows(
        Collection $cells,
        array $reservation,
        int $seaTerrainId,
        int $shallowTerrainId,
        int $minimum,
        DeterministicRandom $random,
    ): void {
        $current = $cells->filter(
            static fn (MapCell $cell): bool => (int) $cell->terrain_definition_id === $shallowTerrainId
                && $cell->owner_nation_id === null
                && $cell->facility_definition_id === null,
        )->count();
        $required = max(0, $minimum - $current);
        if ($required === 0) {
            return;
        }

        $candidates = [];
        foreach ($reservation as $coordinate) {
            $cell = $this->cell($cells, $coordinate);
            if ((int) $cell->terrain_definition_id !== $seaTerrainId
                || $cell->owner_nation_id !== null
                || $cell->facility_definition_id !== null) {
                continue;
            }

            for ($direction = 0; $direction < 6; $direction++) {
                $neighbor = $cells->get($this->key($coordinate->neighbor($direction)));
                if ($neighbor instanceof MapCell
                    && ! in_array(
                        (int) $neighbor->terrain_definition_id,
                        [$seaTerrainId, $shallowTerrainId],
                        true,
                    )) {
                    $candidates[] = $cell;
                    break;
                }
            }
        }

        foreach (array_slice($random->shuffled($candidates), 0, $required) as $cell) {
            $cell->terrain_definition_id = $shallowTerrainId;
            $cell->owner_nation_id = null;
            $cell->facility_definition_id = null;
        }
    }
}
