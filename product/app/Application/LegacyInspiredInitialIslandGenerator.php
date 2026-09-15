<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Nation\NationPlacementUnavailableException;
use App\Domain\World\DeterministicRandom;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\Ship;
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
        $placement = $rules['initial_island_placement'] ?? null;
        if ($placement === null) {
            $reservationTerrainKeys = ['sea'];
            $relocateShips = false;
        } elseif (is_array($placement)
            && ($placement['reservation_terrain_keys'] ?? null) === ['sea', 'shallow', 'wasteland', 'mountain']
            && ($placement['ship_relocation'] ?? null) === 'final_empty_sea_within_reservation'
            && ((! array_key_exists('candidate_evaluation', $placement) && count($placement) === 2)
                || (count($placement) === 3
                    && ($placement['candidate_evaluation'] ?? null) === 'stable_batched_until_safe'))) {
            $reservationTerrainKeys = $placement['reservation_terrain_keys'];
            $relocateShips = true;
        } else {
            throw new DomainException('The active Ruleset has no supported initial-island placement contract.');
        }
        $reservationTerrainIds = array_map(
            static fn (string $key): int => (int) $terrainIds[$key],
            $reservationTerrainKeys,
        );
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
            if (! in_array((int) $cell->terrain_definition_id, $reservationTerrainIds, true)
                || $cell->owner_nation_id !== null
                || $cell->facility_definition_id !== null
                || (int) $cell->population !== 0) {
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

        [$shipRelocations, $npcShipRemovals] = $this->planShipRelocations(
            $mapSpace,
            $cells,
            (int) $terrainIds['sea'],
            $relocateShips,
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
        foreach ($shipRelocations as $relocation) {
            /** @var MapCell $origin */
            $origin = $cells->firstWhere('id', $relocation['from_cell_id']);
            /** @var MapCell $destination */
            $destination = $cells->firstWhere('id', $relocation['to_cell_id']);
            $changedChunks[$origin->map_chunk_id] = true;
            $changedChunks[$destination->map_chunk_id] = true;
        }
        foreach ($npcShipRemovals as $removal) {
            /** @var MapCell $origin */
            $origin = $cells->firstWhere('id', $removal['from_cell_id']);
            $changedChunks[$origin->map_chunk_id] = true;
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
            shipRelocations: $shipRelocations,
            npcShipRemovals: $npcShipRemovals,
        );
    }

    public function apply(InitialIslandPlan $plan, MapSpace $mapSpace, Nation $nation): NationCapital
    {
        $rulesetId = (int) $nation->world()->firstOrFail()->ruleset_version_id;
        if ($plan->mapSpaceId !== $mapSpace->id || $plan->nationId !== $nation->id
            || $plan->rulesetVersionId !== $rulesetId) {
            throw new DomainException('Initial-island plan does not match the locked Nation and ruleset.');
        }
        $this->applyShipRelocations($plan, $mapSpace, $nation);
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

    /**
     * @param  Collection<string, MapCell>  $cells
     * @return array{list<array{ship_id: int, from_cell_id: int, to_cell_id: int, version: int}>, list<array{ship_id: int, from_cell_id: int, version: int}>}
     */
    private function planShipRelocations(
        MapSpace $mapSpace,
        Collection $cells,
        int $seaTerrainId,
        bool $enabled,
    ): array {
        $cellIds = $cells->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $ships = Ship::query()
            ->where('world_id', $mapSpace->world_id)
            ->whereIn('map_cell_id', $cellIds)
            ->where('state', Ship::STATE_ACTIVE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($ships->isEmpty()) {
            return [[], []];
        }
        if (! $enabled) {
            throw new DomainException('選択された海域はすでに使用されています。');
        }

        $monsterCellIds = MonsterOccupancy::query()
            ->whereIn('map_cell_id', $cellIds)
            ->pluck('map_cell_id')
            ->mapWithKeys(static fn ($cellId): array => [(int) $cellId => true])
            ->all();
        $occupiedCellIds = $ships->mapWithKeys(
            static fn (Ship $ship): array => [(int) $ship->map_cell_id => true],
        )->all();
        $relocations = [];
        $removals = [];
        foreach ($ships as $ship) {
            /** @var MapCell|null $origin */
            $origin = $cells->firstWhere('id', $ship->map_cell_id);
            if (! $origin instanceof MapCell) {
                throw new DomainException('Initial-island Ship origin is outside the locked reservation.');
            }
            if ((int) $origin->terrain_definition_id === $seaTerrainId
                && $origin->facility_definition_id === null) {
                continue;
            }

            $originCoordinate = new GridCoordinate($origin->x, $origin->y);
            $destination = $cells
                ->filter(static fn (MapCell $cell): bool => (int) $cell->terrain_definition_id === $seaTerrainId
                    && $cell->owner_nation_id === null
                    && $cell->facility_definition_id === null
                    && (int) $cell->population === 0
                    && ! isset($occupiedCellIds[$cell->id])
                    && ! isset($monsterCellIds[$cell->id]))
                ->sort(static function (MapCell $left, MapCell $right) use ($originCoordinate): int {
                    $leftDistance = $originCoordinate->distanceTo(new GridCoordinate($left->x, $left->y));
                    $rightDistance = $originCoordinate->distanceTo(new GridCoordinate($right->x, $right->y));

                    return [$leftDistance, $left->y, $left->x, $left->id]
                        <=> [$rightDistance, $right->y, $right->x, $right->id];
                })
                ->first();
            if (! $destination instanceof MapCell) {
                if ($ship->nation_id === null) {
                    $removals[] = [
                        'ship_id' => (int) $ship->id,
                        'from_cell_id' => (int) $origin->id,
                        'version' => (int) $ship->version,
                    ];
                    unset($occupiedCellIds[$origin->id]);

                    continue;
                }
                throw new NationPlacementUnavailableException(
                    '初期島生成に巻き込まれる船を安全な海へ退避できません。',
                );
            }

            $occupiedCellIds[$destination->id] = true;
            $relocations[] = [
                'ship_id' => (int) $ship->id,
                'from_cell_id' => (int) $origin->id,
                'to_cell_id' => (int) $destination->id,
                'version' => (int) $ship->version,
            ];
        }

        return [$relocations, $removals];
    }

    private function applyShipRelocations(InitialIslandPlan $plan, MapSpace $mapSpace, Nation $nation): void
    {
        if ($plan->shipRelocations === [] && $plan->npcShipRemovals === []) {
            return;
        }
        $shipIds = array_column($plan->shipRelocations, 'ship_id');
        $destinationIds = array_column($plan->shipRelocations, 'to_cell_id');
        if (count(array_unique($shipIds)) !== count($shipIds)
            || count(array_unique($destinationIds)) !== count($destinationIds)) {
            throw new DomainException('Initial-island Ship relocation plan is not unique.');
        }

        $ships = Ship::query()->whereIn('id', $shipIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $destinations = MapCell::query()->whereIn('id', $destinationIds)
            ->with('terrain')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($ships->count() !== count($shipIds) || $destinations->count() !== count($destinationIds)
            || MonsterOccupancy::query()->whereIn('map_cell_id', $destinationIds)->exists()
            || Ship::query()->whereIn('map_cell_id', $destinationIds)
                ->where('state', Ship::STATE_ACTIVE)->whereNotIn('id', $shipIds)->exists()
            || $ships->pluck('map_cell_id')->intersect($destinationIds)->isNotEmpty()) {
            throw new DomainException('Initial-island Ship relocation preconditions changed.');
        }

        foreach ($plan->shipRelocations as $relocation) {
            /** @var Ship|null $ship */
            $ship = $ships->get($relocation['ship_id']);
            /** @var MapCell|null $destination */
            $destination = $destinations->get($relocation['to_cell_id']);
            if (! $ship instanceof Ship || ! $destination instanceof MapCell
                || (int) $ship->world_id !== (int) $nation->world_id
                || $ship->state !== Ship::STATE_ACTIVE
                || (int) $ship->map_cell_id !== $relocation['from_cell_id']
                || (int) $ship->version !== $relocation['version']
                || (int) $destination->map_space_id !== (int) $mapSpace->id
                || $destination->terrain->key !== 'sea'
                || $destination->owner_nation_id !== null
                || $destination->facility_definition_id !== null
                || (int) $destination->population !== 0) {
                throw new DomainException('Initial-island Ship relocation preconditions changed.');
            }
            $ship->map_cell_id = $destination->id;
            $ship->version++;
            $ship->save();
        }
        foreach ($plan->npcShipRemovals as $removal) {
            $ship = Ship::query()->whereKey($removal['ship_id'])->lockForUpdate()->firstOrFail();
            if ($ship->nation_id !== null || $ship->state !== Ship::STATE_ACTIVE
                || (int) $ship->map_cell_id !== $removal['from_cell_id']
                || (int) $ship->version !== $removal['version']) {
                throw new DomainException('Initial-island NPC Ship removal preconditions changed.');
            }
            $ship->current_hp = 0;
            $ship->map_cell_id = null;
            $ship->state = Ship::STATE_REMOVED;
            $ship->removal_reason = 'initial_island_displacement';
            $ship->removed_at = now();
            $ship->version++;
            $ship->save();
        }
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
