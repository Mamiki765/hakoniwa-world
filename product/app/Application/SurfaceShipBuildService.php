<?php

namespace App\Application;

use App\Domain\Command\CommandFailureReason;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\Ship;
use DomainException;

final class SurfaceShipBuildService
{
    public function __construct(private readonly SurfaceShipCatalog $catalog) {}

    public function failureReason(
        TurnContext $context,
        Nation $nation,
        MapSpace $mapSpace,
        NationCommandQueueItem $item,
        CommandDefinition $command,
    ): ?CommandFailureReason {
        $definition = $this->catalog->resolve($command, $item->quantity);
        $capacity = $this->catalog->capacityPerType($context->ruleset->settings);
        $activeShips = Ship::query()
            ->where('world_id', $context->world->id)
            ->where('nation_id', $nation->id)
            ->where('ship_type_key', $definition->key)
            ->where('state', Ship::STATE_ACTIVE)
            ->limit($capacity)
            ->lockForUpdate()
            ->get(['id']);
        if (count($activeShips->all()) >= $capacity) {
            return CommandFailureReason::ShipCapacityReached;
        }

        $ports = $this->ports($nation, $mapSpace);
        if ($ports === []) {
            return CommandFailureReason::NoPort;
        }
        if ($this->spawnCandidates($mapSpace, $ports) === []) {
            return CommandFailureReason::NoShipSpawnCell;
        }
        if ((int) $nation->money < $definition->buildCostMoney) {
            return CommandFailureReason::InsufficientFunds;
        }

        return null;
    }

    /**
     * @return array{ship: Ship, definition: SurfaceShipDefinition, spawn_distance: int}
     */
    public function build(
        TurnContext $context,
        Nation $nation,
        MapSpace $mapSpace,
        NationCommandQueueItem $item,
        CommandDefinition $command,
    ): array {
        $definition = $this->catalog->resolve($command, $item->quantity);
        $capacity = $this->catalog->capacityPerType($context->ruleset->settings);
        $activeShips = Ship::query()
            ->where('world_id', $context->world->id)
            ->where('nation_id', $nation->id)
            ->where('ship_type_key', $definition->key)
            ->where('state', Ship::STATE_ACTIVE)
            ->limit($capacity)
            ->lockForUpdate()
            ->get(['id']);
        if (count($activeShips->all()) >= $capacity) {
            throw new DomainException('Surface Ship capacity changed after command validation.');
        }

        $ports = $this->ports($nation, $mapSpace);
        $candidates = $this->spawnCandidates($mapSpace, $ports);
        if ($candidates === []) {
            throw new DomainException('Surface Ship spawn candidates changed after command validation.');
        }
        $selected = $candidates[0];
        if (count($candidates) > 1) {
            $index = $context->random
                ->stream(TurnRandomStreamFactory::shipBuild($item->id))
                ->integer(0, count($candidates) - 1);
            $selected = $candidates[$index];
        }

        $ship = Ship::query()->create([
            'world_id' => $context->world->id,
            'ruleset_version_id' => $context->ruleset->id,
            'nation_id' => $nation->id,
            'map_cell_id' => $selected['cell']->id,
            'ship_type_key' => $definition->key,
            'current_hp' => $definition->maximumHp,
            'max_hp' => $definition->maximumHp,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
            'removal_reason' => null,
            'removed_at' => null,
        ]);
        $context->state->markMapChunkChanged($selected['cell']->map_chunk_id);

        return [
            'ship' => $ship,
            'definition' => $definition,
            'spawn_distance' => $selected['distance'],
        ];
    }

    /** @return list<MapCell> */
    private function ports(Nation $nation, MapSpace $mapSpace): array
    {
        return MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $nation->id)
            ->whereHas('facility', static fn ($query) => $query->where('key', 'port'))
            ->orderBy('x')
            ->orderBy('y')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /**
     * @param  list<MapCell>  $ports
     * @return list<array{cell: MapCell, distance: int}>
     */
    private function spawnCandidates(MapSpace $mapSpace, array $ports): array
    {
        /** @var array<string, int> $distanceByCoordinate */
        $distanceByCoordinate = [];
        $candidateXs = [];
        $candidateYs = [];
        foreach ($ports as $port) {
            $origin = new GridCoordinate($port->x, $port->y);
            foreach ([1, 2] as $distance) {
                foreach ($origin->ring($distance) as $coordinate) {
                    if ($coordinate->x < $mapSpace->min_x || $coordinate->x > $mapSpace->max_x
                        || $coordinate->y < $mapSpace->min_y || $coordinate->y > $mapSpace->max_y) {
                        continue;
                    }
                    $key = $coordinate->x.':'.$coordinate->y;
                    $distanceByCoordinate[$key] = min($distance, $distanceByCoordinate[$key] ?? $distance);
                    $candidateXs[] = $coordinate->x;
                    $candidateYs[] = $coordinate->y;
                }
            }
        }
        if ($distanceByCoordinate === []) {
            return [];
        }

        $cells = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->whereBetween('x', [min($candidateXs), max($candidateXs)])
            ->whereBetween('y', [min($candidateYs), max($candidateYs)])
            ->whereHas('terrain', static fn ($query) => $query->where('key', 'sea'))
            ->whereDoesntHave('ship')
            ->whereDoesntHave('monsterOccupancy')
            ->with('facility')
            ->orderBy('x')
            ->orderBy('y')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $candidates = [];
        $nearestDistance = null;
        foreach ($cells as $cell) {
            $distance = $distanceByCoordinate[$cell->x.':'.$cell->y] ?? null;
            if ($distance === null || ($nearestDistance !== null && $distance > $nearestDistance)) {
                continue;
            }
            $facility = $cell->facility;
            if ($facility !== null
                && ($facility->visibility_policy !== FacilityVisibilityPolicy::Disguised->value
                    || $facility->disguise_terrain_key !== 'sea')) {
                continue;
            }
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $candidates = [];
            }
            $candidates[] = ['cell' => $cell, 'distance' => $distance];
        }

        return $candidates;
    }
}
