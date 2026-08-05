<?php

namespace App\Application;

use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Monster\MonsterNaturalSpawnPolicy;
use App\Domain\Monster\MonsterSpawnSource;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class MonsterSpawnService
{
    private ?TerrainDefinition $wasteland = null;

    public function __construct(
        private readonly NationLandAreaCalculator $landArea,
        private readonly MonsterNaturalSpawnPolicy $policy,
        private readonly MapCellStateService $cells,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array<string, int> */
    public function spawnNatural(TurnContext $context, MapSpace $space): array
    {
        $metrics = [
            'eligible_spawn_nations' => 0,
            'spawn_draws' => 0,
            'monsters_spawned' => 0,
            'blocked_no_settlement' => 0,
        ];
        $system = $context->ruleset->settings['monster_system']['natural_spawn'] ?? null;
        if (! is_array($system)) {
            throw new DomainException('The active ruleset is missing Nation-scoped monster spawn settings.');
        }
        $definitions = MonsterDefinition::query()
            ->where('ruleset_version_id', $context->ruleset->id)
            ->orderBy('id')
            ->get()
            ->keyBy('key');
        if ($definitions->count() !== 8) {
            throw new DomainException('The active ruleset does not have the exact PR21 monster catalog.');
        }

        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $occupied = MonsterOccupancy::query()
            ->whereIn('map_cell_id', $cells->pluck('id'))
            ->pluck('map_cell_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();
        $landByNation = $this->landArea->byNation($cells);

        /** @var array<int, int> $populationByNation */
        $populationByNation = [];
        /** @var array<int, list<MapCell>> $candidatesByNation */
        $candidatesByNation = [];
        $settlementKeys = $system['settlement_facility_keys'] ?? [];
        foreach ($cells as $cell) {
            if ($cell->owner_nation_id === null) {
                continue;
            }
            $populationByNation[$cell->owner_nation_id] = ($populationByNation[$cell->owner_nation_id] ?? 0)
                + $cell->population;
            if ($cell->population > 0
                && in_array($cell->facility?->key, $settlementKeys, true)
                && ! $occupied->has($cell->id)) {
                $candidatesByNation[$cell->owner_nation_id][] = $cell;
            }
        }

        $nations = Nation::query()
            ->where('world_id', $context->world->id)
            ->where('state', $system['eligible_nation_state'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $minimumPopulation = $system['minimum_population'] ?? null;
        $streamVersion = $system['stream_version'] ?? null;
        if (! is_int($minimumPopulation) || ! is_int($streamVersion)) {
            throw new DomainException('The active ruleset has invalid monster spawn arithmetic.');
        }
        $this->policy->probabilityForLand($system, 0);

        // All eligibility data above is a single pre-application snapshot. Applying a
        // spawn for one Nation cannot change another Nation's candidate set or draw.
        foreach ($nations as $nation) {
            $population = $populationByNation[$nation->id] ?? 0;
            if ($population < $minimumPopulation) {
                continue;
            }
            $pool = $this->policy->poolForPopulation($system, $population);
            if ($pool === []) {
                continue;
            }
            $metrics['eligible_spawn_nations']++;
            $metrics['spawn_draws']++;
            $ownedLandCells = $landByNation[$nation->id] ?? 0;
            $spawnProbability = $this->policy->probabilityForLand($system, $ownedLandCells);
            $triggerDraw = $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'trigger', $streamVersion),
            )->integer(0, $spawnProbability['denominator'] - 1);
            if ($triggerDraw >= $spawnProbability['numerator']) {
                continue;
            }

            $candidates = $candidatesByNation[$nation->id] ?? [];
            if ($candidates === []) {
                $metrics['blocked_no_settlement']++;
                $this->events->record($context, 'monster.spawn_failed_no_settlement', $nation, [
                    'nation_id' => $nation->id,
                    'nation_number' => $nation->nation_number,
                    'owned_land_cells' => $ownedLandCells,
                    'population' => $population,
                ]);

                continue;
            }
            $candidateIndex = $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'candidate', $streamVersion),
            )->integer(0, count($candidates) - 1);
            $cell = $candidates[$candidateIndex];
            $typeIndex = $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'type', $streamVersion),
            )->integer(0, count($pool) - 1);
            /** @var MonsterDefinition|null $definition */
            $definition = $definitions->get($pool[$typeIndex]);
            if ($definition === null || $definition->key === 'mecha_inora') {
                throw new DomainException('Natural spawn selected an invalid monster definition.');
            }
            $hp = $definition->base_hp + $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'hp', $streamVersion),
            )->integer(0, $definition->hp_variation);

            $beforeFacility = $cell->facility?->key;
            $beforePopulation = $cell->population;
            $this->cells->setFacility($cell, null);
            $this->cells->transitionTerrain($cell, $this->wasteland());
            $cell->population = 0;
            $cell->version++;
            $cell->save();
            $monster = MonsterInstance::query()->create([
                'world_id' => $context->world->id,
                'monster_definition_id' => $definition->id,
                'current_hp' => $hp,
                'spawned_max_hp' => $hp,
                'state' => 'alive',
                'spawned_target_turn' => $context->targetTurn,
                'version' => 1,
            ]);
            MonsterOccupancy::query()->create([
                'monster_instance_id' => $monster->id,
                'map_cell_id' => $cell->id,
            ]);
            $context->state->markMapChunkChanged($cell->map_chunk_id);
            $metrics['monsters_spawned']++;
            $this->events->record($context, 'monster.spawned', $monster, [
                'monster_key' => $definition->key,
                'nation_id' => $nation->id,
                'nation_number' => $nation->nation_number,
                'x' => $cell->x,
                'y' => $cell->y,
                'initial_hp' => $hp,
                'removed_facility_key' => $beforeFacility,
                'before_population' => $beforePopulation,
                'after_population' => 0,
                'owner_preserved' => true,
                'spawn_source' => MonsterSpawnSource::Natural->value,
            ]);
        }

        return $metrics;
    }

    public function hasDispatchCandidate(TurnContext $context, Nation $target): bool
    {
        return $this->dispatchCandidates($context, $target, false)->isNotEmpty();
    }

    public function dispatch(TurnContext $context, Nation $target, int $queueItemId): MonsterInstance
    {
        if ($target->world_id !== $context->world->id || $target->state !== 'active') {
            throw new DomainException('A dispatched monster requires an active target Nation in the current World.');
        }
        $candidates = $this->dispatchCandidates($context, $target, true);
        if ($candidates->isEmpty()) {
            throw new DomainException('A dispatched monster lost its eligible settlement before execution.');
        }
        $index = $context->random->stream(TurnRandomStreamFactory::monsterDispatch($queueItemId))
            ->integer(0, $candidates->count() - 1);
        /** @var MapCell $cell */
        $cell = $candidates->values()->get($index);
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $context->ruleset->id)
            ->where('key', 'mecha_inora')
            ->firstOrFail();
        $beforeFacility = $cell->facility?->key;
        $beforePopulation = $cell->population;
        $this->cells->setFacility($cell, null);
        $this->cells->transitionTerrain($cell, $this->wasteland());
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $monster = MonsterInstance::query()->create([
            'world_id' => $context->world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp,
            'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive',
            'spawned_target_turn' => $context->targetTurn,
            'version' => 1,
        ]);
        $context->state->recordMonsterSpawned($monster->id, MonsterSpawnSource::MonsterDispatchCommand);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'monster.spawned', $monster, [
            'monster_key' => $definition->key,
            'nation_id' => $target->id,
            'nation_number' => $target->nation_number,
            'x' => $cell->x,
            'y' => $cell->y,
            'initial_hp' => $definition->base_hp,
            'removed_facility_key' => $beforeFacility,
            'before_population' => $beforePopulation,
            'after_population' => 0,
            'owner_preserved' => true,
            'spawn_source' => MonsterSpawnSource::MonsterDispatchCommand->value,
            'queue_item_id' => $queueItemId,
        ]);

        return $monster;
    }

    /** @return Collection<int, MapCell> */
    private function dispatchCandidates(TurnContext $context, Nation $target, bool $lock)
    {
        $keys = $context->ruleset->settings['monster_system']['natural_spawn']['settlement_facility_keys'] ?? null;
        if (! is_array($keys) || $keys === []) {
            throw new DomainException('Monster dispatch requires the PR21 settlement eligibility contract.');
        }
        $query = MapCell::query()
            ->where('owner_nation_id', $target->id)
            ->where('population', '>', 0)
            ->whereHas('facility', fn ($facility) => $facility->whereIn('key', $keys))
            ->whereDoesntHave('monsterOccupancy')
            ->with(['terrain', 'facility'])
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function wasteland(): TerrainDefinition
    {
        return $this->wasteland ??= TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
    }
}
