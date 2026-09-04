<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Monster\MonsterBehaviorResolver;
use App\Domain\Monster\MonsterSpawnSource;
use App\Domain\Nation\NationProtectionPolicy;
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

final class MonsterWorldSpawnService
{
    private ?TerrainDefinition $sea = null;

    public function __construct(
        private readonly MonsterBehaviorResolver $behaviors,
        private readonly NationLandAreaCalculator $landArea,
        private readonly MapCellStateService $cells,
        private readonly TurnEventRecorder $events,
        private readonly NationProtectionPolicy $nationProtection,
    ) {}

    /** @return array<string, int> */
    public function spawn(TurnContext $context, MapSpace $space): array
    {
        $authored = $context->ruleset->settings['monster_definitions'] ?? null;
        if (! is_array($authored) || ! array_is_list($authored)) {
            return [];
        }
        $worldDefinition = null;
        $behavior = null;
        foreach ($authored as $definition) {
            if (! is_array($definition) || ! is_array($definition['source_metadata'] ?? null)) {
                continue;
            }
            $candidateBehavior = $this->behaviors->resolve($definition['source_metadata'], (string) ($definition['key'] ?? ''));
            if ($candidateBehavior->worldSpawn !== null) {
                if ($worldDefinition !== null) {
                    throw new DomainException('Only one World monster-spawn behavior may be active.');
                }
                $worldDefinition = $definition;
                $behavior = $candidateBehavior;
            }
        }
        if ($worldDefinition === null || $behavior?->worldSpawn === null) {
            return [];
        }
        $settings = $behavior->worldSpawn;
        $metrics = [
            'world_sea_monster_spawn_draws' => 0,
            'world_sea_monsters_spawned' => 0,
            'world_sea_spawn_candidates' => 0,
            'world_sea_spawn_blocked_no_candidate' => 0,
        ];
        $activeNationIds = Nation::query()->where('world_id', $context->world->id)
            ->where('state', 'active')->orderBy('id')->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $activeOwnedLand = min(
            (int) $settings['maximum_probability_numerator'],
            array_sum($this->landArea->forNationIds($context->world, $activeNationIds)),
        );
        if ($activeOwnedLand === 0) {
            return $metrics;
        }
        $streamVersion = (int) $settings['stream_version'];
        $draw = $context->random->stream(
            TurnRandomStreamFactory::monsterWorldSpawn('trigger', $streamVersion),
        )->integer(0, (int) $settings['probability_per_active_owned_land_cell']['denominator'] - 1);
        $metrics['world_sea_monster_spawn_draws'] = 1;
        if ($draw >= $activeOwnedLand) {
            return $metrics;
        }

        $surfaceCells = MapCell::query()->where('map_space_id', $space->id)
            ->with(['terrain', 'facility', 'ship'])->orderBy('id')->lockForUpdate()->get();
        $blockedByLand = [];
        $minimumDistance = (int) $settings['minimum_land_distance'];
        foreach ($surfaceCells as $cell) {
            if ($this->landArea->isLand($cell)) {
                foreach ((new GridCoordinate($cell->x, $cell->y))->radius($minimumDistance - 1) as $blocked) {
                    $blockedByLand[$blocked->x.':'.$blocked->y] = true;
                }
            }
        }
        $occupiedCellIds = array_fill_keys(
            MonsterOccupancy::query()->whereIn('map_cell_id', $surfaceCells->modelKeys())
                ->pluck('map_cell_id')->map(static fn ($id): int => (int) $id)->all(),
            true,
        );
        $candidates = $surfaceCells->filter(function (MapCell $cell) use ($context, $settings, $blockedByLand, $occupiedCellIds): bool {
            if (! in_array($cell->terrain->key, $settings['terrain_keys'], true)
                || $cell->owner_nation_id !== null
                || $cell->population !== 0
                || $cell->facility_definition_id !== null
                || $cell->ship !== null
                || isset($occupiedCellIds[$cell->id])) {
                return false;
            }
            if ($this->nationProtection->protects($context, $cell->x, $cell->y)) {
                return false;
            }

            return ! isset($blockedByLand[$cell->x.':'.$cell->y]);
        })->values();
        $metrics['world_sea_spawn_candidates'] = $candidates->count();
        if ($candidates->isEmpty()) {
            $metrics['world_sea_spawn_blocked_no_candidate'] = 1;

            return $metrics;
        }
        $candidateIndex = $context->random->stream(
            TurnRandomStreamFactory::monsterWorldSpawn('candidate', $streamVersion),
        )->integer(0, $candidates->count() - 1);
        /** @var MapCell $cell */
        $cell = $candidates->get($candidateIndex);
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $context->ruleset->id)
            ->where('key', $worldDefinition['key'])->firstOrFail();
        $hp = $definition->base_hp + $context->random->stream(
            TurnRandomStreamFactory::monsterWorldSpawn('hp', $streamVersion),
        )->integer(0, $definition->hp_variation);
        $beforeTerrain = $cell->terrain->key;
        $this->cells->setFacility($cell, null);
        $this->cells->transitionTerrain($cell, $this->sea());
        $cell->owner_nation_id = null;
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
        $context->state->recordMonsterSpawned($monster->id, MonsterSpawnSource::WorldAoiDisaster);
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'monster.spawned', $monster, [
            'monster_key' => $definition->key,
            'nation_id' => null,
            'x' => $cell->x,
            'y' => $cell->y,
            'initial_hp' => $hp,
            'before_terrain_key' => $beforeTerrain,
            'to_terrain_key' => 'sea',
            'spawn_source' => MonsterSpawnSource::WorldAoiDisaster->value,
            'owner_preserved' => false,
        ]);
        $metrics['world_sea_monsters_spawned'] = 1;

        return $metrics;
    }

    private function sea(): TerrainDefinition
    {
        return $this->sea ??= TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
    }
}
