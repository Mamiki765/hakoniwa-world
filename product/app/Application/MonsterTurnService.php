<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Monster\MonsterHardening;
use App\Domain\Monster\MonsterTurnBatch;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\TerrainDefinition;
use DomainException;

final class MonsterTurnService
{
    private ?TerrainDefinition $wasteland = null;

    public function __construct(
        private readonly MapCellStateService $cells,
        private readonly MonsterHardening $hardening,
        private readonly MonsterRemovalService $removal,
        private readonly TurnEventRecorder $events,
        private readonly DisasterTurnService $disasters,
    ) {}

    public function load(TurnContext $context): MonsterTurnBatch
    {
        $occupancies = MonsterOccupancy::query()
            ->whereHas('monster', fn ($query) => $query
                ->where('world_id', $context->world->id)
                ->where('state', 'alive')
                ->whereHas('definition', fn ($definition) => $definition
                    ->where('ruleset_version_id', $context->ruleset->id)))
            ->with(['monster.definition'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $batch = new MonsterTurnBatch($occupancies);
        $this->removal->useBatch($batch);

        return $batch;
    }

    /**
     * @param  array<string, MapCell>  $cellsByCoordinate
     */
    public function processCell(
        TurnContext $context,
        MapSpace $space,
        MapCell $cell,
        array $cellsByCoordinate,
        MonsterTurnBatch $batch,
    ): bool {
        $occupancy = $batch->occupancyAt($cell->id);
        if ($occupancy === null) {
            return false;
        }
        $batch->countAction();
        $monster = $occupancy->monster;
        $definition = $monster->definition;
        if ($this->hardening->isHardened($definition, $context->targetTurn)) {
            $this->recordStayed($context, $monster->id, $definition->key, $cell, 'hardened');

            return true;
        }
        if ($batch->movesTaken($monster->id) >= $definition->movement_limit) {
            $this->recordStayed($context, $monster->id, $definition->key, $cell, 'movement_limit');

            return true;
        }

        $movement = $definition->movement_terrain_contract;
        $attempts = $movement['candidate_attempts_per_action'] ?? null;
        if (! is_int($attempts) || $attempts !== 3) {
            throw new DomainException('The active monster definition has an invalid movement contract.');
        }
        $streamVersion = $context->ruleset->settings['monster_system']['natural_spawn']['stream_version'] ?? null;
        if (! is_int($streamVersion) || $streamVersion < 1) {
            throw new DomainException('The active ruleset is missing the monster stream version.');
        }
        $stream = $context->random->stream(
            TurnRandomStreamFactory::monsterMovement($monster->id, $streamVersion),
        );
        $origin = new GridCoordinate($cell->x, $cell->y);
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $coordinate = $origin->neighbor($stream->integer(0, 5));
            if ($coordinate->x < $space->min_x || $coordinate->x > $space->max_x
                || $coordinate->y < $space->min_y || $coordinate->y > $space->max_y) {
                continue;
            }
            $destination = $cellsByCoordinate[$coordinate->x.':'.$coordinate->y] ?? null;
            if ($destination === null || $batch->occupancyAt($destination->id) !== null) {
                continue;
            }
            $facilityKey = $destination->facility?->key;
            if ($facilityKey === ($movement['defense_facility_key'] ?? null)) {
                $this->defenseSelfDestruct(
                    $context,
                    $space,
                    $cell,
                    $destination,
                    $batch,
                    $cellsByCoordinate,
                );

                return true;
            }
            if (in_array($destination->terrain->key, $movement['blocked_terrain_keys'] ?? [], true)
                || in_array($facilityKey, $movement['blocked_facility_keys'] ?? [], true)) {
                continue;
            }

            $this->moveAndTrample($context, $cell, $destination, $occupancy, $batch);

            return true;
        }

        $this->recordStayed($context, $monster->id, $definition->key, $cell, 'no_candidate');

        return true;
    }

    private function moveAndTrample(
        TurnContext $context,
        MapCell $origin,
        MapCell $destination,
        MonsterOccupancy $occupancy,
        MonsterTurnBatch $batch,
    ): void {
        $monster = $occupancy->monster;
        $beforeTerrain = $destination->terrain->key;
        $beforeFacility = $destination->facility?->key;
        $beforePopulation = $destination->population;
        $this->cells->setFacility($destination, null);
        $this->cells->transitionTerrain($destination, $this->wasteland());
        $destination->population = 0;
        $destination->version++;
        $destination->save();

        $fromCellId = $occupancy->map_cell_id;
        $occupancy->map_cell_id = $destination->id;
        $occupancy->save();
        $batch->move($occupancy, $fromCellId, $destination->id);
        $monster->version++;
        $monster->save();
        $context->state->markMapChunkChanged($origin->map_chunk_id);
        $context->state->markMapChunkChanged($destination->map_chunk_id);

        $metadata = [
            'monster_key' => $monster->definition->key,
            'nation_id' => $destination->owner_nation_id,
            'from_x' => $origin->x,
            'from_y' => $origin->y,
            'x' => $destination->x,
            'y' => $destination->y,
            'from_terrain_key' => $beforeTerrain,
            'to_terrain_key' => 'wasteland',
            'removed_facility_key' => $beforeFacility,
            'before_population' => $beforePopulation,
            'after_population' => 0,
            'owner_preserved' => true,
        ];
        $this->events->record($context, 'monster.moved', $monster, $metadata);
        $this->events->record($context, 'monster.trampled', $destination, $metadata);
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    private function defenseSelfDestruct(
        TurnContext $context,
        MapSpace $space,
        MapCell $origin,
        MapCell $defense,
        MonsterTurnBatch $batch,
        array $cellsByCoordinate,
    ): void {
        $occupancy = $batch->occupancyAt($origin->id);
        if ($occupancy === null) {
            throw new DomainException('Defense self-destruct lost the moving monster occupancy.');
        }
        $monster = $occupancy->monster;
        $this->removal->removeInstance(
            $context,
            $monster,
            $origin,
            'defense_self_destruct',
            'monster.defense_self_destructed',
            [
                'center_x' => $defense->x,
                'center_y' => $defense->y,
                'defense_owner_nation_id' => $defense->owner_nation_id,
                'hardening_ignored' => true,
            ],
        );
        $batch->countDefenseSelfDestruct();
        $settings = $context->ruleset->settings['turn_processing']['disasters']['huge_meteor'] ?? null;
        if (! is_array($settings)) {
            throw new DomainException('Defense self-destruct requires huge-meteor settings.');
        }
        $damagedCells = $this->disasters->resolveHugeMeteorBlast(
            $context,
            $space,
            new GridCoordinate($defense->x, $defense->y),
            $settings,
            'defense_self_destruct',
        );
        $this->events->record($context, 'disaster.triggered', $context->world, [
            'disaster_key' => 'defense_self_destruct',
            'center_x' => $defense->x,
            'center_y' => $defense->y,
            'trigger' => 'monster_contact',
            'random_draw_used' => false,
            'damaged_cells' => $damagedCells,
        ]);
        foreach ((new GridCoordinate($defense->x, $defense->y))->radius(2) as $coordinate) {
            $cell = $cellsByCoordinate[$coordinate->x.':'.$coordinate->y] ?? null;
            if ($cell !== null) {
                $cell->refresh()->load(['terrain', 'facility']);
            }
        }
    }

    private function recordStayed(
        TurnContext $context,
        int $monsterId,
        string $monsterKey,
        MapCell $cell,
        string $reason,
    ): void {
        $this->events->record($context, 'monster.stayed', null, [
            'monster_id' => $monsterId,
            'monster_key' => $monsterKey,
            'nation_id' => $cell->owner_nation_id,
            'x' => $cell->x,
            'y' => $cell->y,
            'reason' => $reason,
        ]);
    }

    private function wasteland(): TerrainDefinition
    {
        return $this->wasteland ??= TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
    }
}
