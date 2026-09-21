<?php

namespace App\Application;

use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\Ship;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class SurfaceShipForcedDisplacementService
{
    public function __construct(
        private readonly SurfaceShipRemovalService $removal,
        private readonly TurnEventRecorder $events,
        private readonly NationRecoveryExitService $recoveryExit,
    ) {}

    public function displace(
        TurnContext $context,
        MapCell $origin,
        Nation $actingNation,
        string $reason,
        int $queueItemId,
    ): void {
        $ship = Ship::query()
            ->where('world_id', $context->world->id)
            ->where('map_cell_id', $origin->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $ship instanceof Ship) {
            return;
        }
        $space = MapSpace::query()->whereKey($origin->map_space_id)->lockForUpdate()->firstOrFail();
        $maximumMapDistance = ((int) $space->max_x - (int) $space->min_x)
            + ((int) $space->max_y - (int) $space->min_y);
        $settings = $this->settings($context, $maximumMapDistance);
        $destination = $this->randomCandidate(
            $context,
            $ship,
            $this->candidates($space, (new GridCoordinate($origin->x, $origin->y))->ring(1), (int) $origin->id),
            'adjacent',
            (int) $settings['random_stream_version'],
        );
        $source = 'adjacent';
        if (! $destination instanceof MapCell) {
            $destination = $this->portCandidate($context, $space, $origin, $ship, $settings);
            $source = 'port';
        }
        if (! $destination instanceof MapCell) {
            $foreign = $ship->nation_id !== null && (int) $actingNation->id !== (int) $ship->nation_id;
            $this->removal->sinkLockedAtCell($context, $origin, $ship, 'forced_displacement_failed', [
                'cause' => $reason,
                'acting_nation_id' => (int) $actingNation->id,
            ]);
            if ($foreign) {
                $crimePoints = (int) $settings['foreign_destroy_karma'];
                $context->state->addKarmaCrime(
                    (int) $actingNation->id,
                    $crimePoints,
                );
                if ($actingNation->state === 'recovery') {
                    $this->recoveryExit->exitForCrime($context, $actingNation, $crimePoints, $queueItemId);
                }
            }

            return;
        }

        $ship->map_cell_id = $destination->id;
        $ship->version++;
        $ship->save();
        $context->state->markMapChunkChanged((int) $origin->map_chunk_id);
        $context->state->markMapChunkChanged((int) $destination->map_chunk_id);
        $this->events->record($context, 'ship.forced_displaced', $ship, [
            'nation_id' => $ship->nation_id,
            'ship_id' => (int) $ship->id,
            'ship_type_key' => $ship->ship_type_key,
            'cause' => $reason,
            'acting_nation_id' => (int) $actingNation->id,
            'from_x' => (int) $origin->x,
            'from_y' => (int) $origin->y,
            'x' => (int) $destination->x,
            'y' => (int) $destination->y,
            'source' => $source,
            'oil_consumed' => 0,
            'movement_reward' => 0,
            'secretary_experience' => 0,
            'normal_event_consumed' => false,
        ], $ship->nation_id === null ? 'public' : 'nation', 'warning');
    }

    /** @param array<string, mixed> $settings */
    private function portCandidate(
        TurnContext $context,
        MapSpace $space,
        MapCell $origin,
        Ship $ship,
        array $settings,
    ): ?MapCell {
        if ($ship->nation_id === null) {
            return null;
        }

        $originCoordinate = new GridCoordinate($origin->x, $origin->y);
        $ports = MapCell::query()
            ->where('map_space_id', $space->id)
            ->where('owner_nation_id', $ship->nation_id)
            ->whereHas('facility', fn ($query) => $query->where('key', $settings['required_port_facility_key']))
            ->lockForUpdate()
            ->get()
            ->sort(static function (MapCell $left, MapCell $right) use ($originCoordinate): int {
                $leftDistance = $originCoordinate->distanceTo(new GridCoordinate($left->x, $left->y));
                $rightDistance = $originCoordinate->distanceTo(new GridCoordinate($right->x, $right->y));

                return [$leftDistance, $left->x, $left->y, $left->id]
                    <=> [$rightDistance, $right->x, $right->y, $right->id];
            })
            ->values();
        foreach ($ports as $port) {
            $portCoordinate = new GridCoordinate($port->x, $port->y);
            foreach ($settings['port_search_distances'] as $distance) {
                $candidate = $this->randomCandidate(
                    $context,
                    $ship,
                    $this->candidates($space, $portCoordinate->ring($distance), (int) $origin->id),
                    'port',
                    (int) $settings['random_stream_version'],
                );
                if ($candidate instanceof MapCell) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<GridCoordinate>  $coordinates
     * @return list<MapCell>
     */
    private function candidates(MapSpace $space, array $coordinates, int $excludedCellId): array
    {
        /** @var array<int, array<int, true>> $ysByX */
        $ysByX = [];
        foreach ($coordinates as $coordinate) {
            if ($coordinate->x >= $space->min_x && $coordinate->x <= $space->max_x
                && $coordinate->y >= $space->min_y && $coordinate->y <= $space->max_y) {
                $ysByX[$coordinate->x][$coordinate->y] = true;
            }
        }
        if ($ysByX === []) {
            return [];
        }
        ksort($ysByX, SORT_NUMERIC);
        foreach ($ysByX as &$ys) {
            ksort($ys, SORT_NUMERIC);
        }
        unset($ys);

        return MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereKeyNot($excludedCellId)
            ->where(function (Builder $query) use ($ysByX): void {
                foreach ($ysByX as $x => $ys) {
                    $query->orWhere(fn (Builder $sameX) => $sameX->where('x', $x)->whereIn('y', array_keys($ys)));
                }
            })
            ->whereHas('terrain', static fn ($query) => $query->where('key', 'sea'))
            ->whereDoesntHave('ship')
            ->whereDoesntHave('monsterOccupancy')
            ->with('facility')
            ->orderBy('x')->orderBy('y')->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(static function (MapCell $cell): bool {
                return $cell->facility === null
                    || ($cell->facility->visibility_policy === FacilityVisibilityPolicy::Disguised->value
                        && $cell->facility->disguise_terrain_key === 'sea');
            })
            ->values()
            ->all();
    }

    /** @param list<MapCell> $candidates */
    private function randomCandidate(
        TurnContext $context,
        Ship $ship,
        array $candidates,
        string $purpose,
        int $streamVersion,
    ): ?MapCell {
        if ($candidates === []) {
            return null;
        }
        $index = $context->random->stream(
            TurnRandomStreamFactory::shipDisplacement((int) $ship->id, $purpose, $streamVersion),
        )->integer(0, count($candidates) - 1);

        return $candidates[$index];
    }

    /** @return array{port_search_distances: list<int>, foreign_destroy_karma: int, random_stream_version: int, required_port_facility_key: string} */
    private function settings(TurnContext $context, int $maximumMapDistance): array
    {
        $displacement = $context->ruleset->settings['surface_ships']['forced_displacement'] ?? null;
        $movement = $context->ruleset->settings['surface_ships']['movement'] ?? null;
        $distances = is_array($displacement) ? ($displacement['port_search_distances'] ?? null) : null;
        $streamVersion = is_array($movement) ? ($movement['random_stream_version'] ?? null) : null;
        $portFacilityKey = is_array($movement) ? ($movement['required_port_facility_key'] ?? null) : null;
        if (! is_array($displacement)
            || ! is_array($distances)
            || ! array_is_list($distances)
            || $distances === []
            || count($distances) > 100
            || array_filter(
                $distances,
                static fn (mixed $distance): bool => ! is_int($distance)
                    || $distance < 1
                    || $distance > $maximumMapDistance,
            ) !== []
            || count(array_unique($distances)) !== count($distances)
            || ! is_int($displacement['foreign_destroy_karma'] ?? null)
            || $displacement['foreign_destroy_karma'] < 0
            || ! is_int($streamVersion)
            || $streamVersion < 1
            || ! is_string($portFacilityKey)
            || $portFacilityKey === '') {
            throw new DomainException('The active Ruleset has no supported forced Ship displacement contract.');
        }

        return [
            ...$displacement,
            'random_stream_version' => $streamVersion,
            'required_port_facility_key' => $portFacilityKey,
        ];
    }
}
