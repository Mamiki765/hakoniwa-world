<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Monster\MonsterBehaviorResolver;
use App\Domain\Monster\MonsterSpawnSource;
use App\Domain\Nation\NationProtectionPolicy;
use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Secretary\SecretaryItemGameplayContract;
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
        private readonly SecretaryItemEffectAggregator $secretaryItems,
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

        $nearshore = ($settings['stream_version'] ?? null) === 2;
        $targetNationId = null;
        if ($nearshore) {
            $populationRows = MapCell::query()->where('map_space_id', $space->id)
                ->whereIn('owner_nation_id', $activeNationIds)
                ->selectRaw('owner_nation_id, SUM(population) AS aggregate')->groupBy('owner_nation_id')
                ->pluck('aggregate', 'owner_nation_id');
            $landByNation = $this->landArea->forNationIds($context->world, $activeNationIds);
            $weights = [];
            foreach ($activeNationIds as $nationId) {
                if ((int) ($populationRows[$nationId] ?? 0) < (int) $settings['minimum_nation_population']) {
                    continue;
                }
                $itemPercent = $this->secretaryItems->snapshotPercentage(
                    $context->state,
                    $nationId,
                    SecretaryItemGameplayContract::NATURAL_MONSTER_SPAWN_PERCENT,
                    'normal_nation_natural_spawn',
                );
                $weight = (int) ($landByNation[$nationId] ?? 0) * max(0, 100 + $itemPercent);
                if ($weight > 0) {
                    $weights[$nationId] = $weight;
                }
            }
            if ($weights === []) {
                $metrics['world_sea_spawn_blocked_no_candidate'] = 1;

                return $metrics;
            }
            $weightDraw = $context->random->stream(TurnRandomStreamFactory::monsterWorldSpawn(
                'target_nation', (int) $settings['stream_version'],
            ))->integer(1, array_sum($weights));
            foreach ($weights as $nationId => $weight) {
                $weightDraw -= $weight;
                if ($weightDraw <= 0) {
                    $targetNationId = $nationId;
                    break;
                }
            }
        }

        $shipOccupancyEnabled = is_array($context->ruleset->settings['surface_ships'] ?? null);
        $relations = ['terrain', 'facility'];
        if ($shipOccupancyEnabled) {
            $relations[] = 'ship';
        }
        $surfaceCells = MapCell::query()->where('map_space_id', $space->id)
            ->with($relations)->orderBy('id')->lockForUpdate()->get();
        $landCells = $surfaceCells->filter(fn (MapCell $cell): bool => $this->landArea->isLand($cell));
        $selectedLand = $nearshore
            ? $landCells->where('owner_nation_id', $targetNationId)->values()
            : collect();
        $otherLand = $nearshore
            ? $landCells->filter(
                static fn (MapCell $cell): bool => (int) ($cell->owner_nation_id ?? 0) !== $targetNationId,
            )->values()
            : collect();
        $blockedByLand = [];
        if (! $nearshore) {
            $minimumDistance = (int) $settings['minimum_land_distance'];
            foreach ($landCells as $cell) {
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
        $candidates = $surfaceCells->filter(function (MapCell $cell) use (
            $context, $settings, $blockedByLand, $occupiedCellIds, $shipOccupancyEnabled,
            $nearshore, $selectedLand, $otherLand,
        ): bool {
            if (! in_array($cell->terrain->key, $settings['terrain_keys'], true)
                || $cell->owner_nation_id !== null
                || $cell->population !== 0
                || $cell->facility_definition_id !== null
                || ($shipOccupancyEnabled && $cell->ship !== null)
                || isset($occupiedCellIds[$cell->id])) {
                return false;
            }
            if ($this->nationProtection->protects($context, $cell->x, $cell->y)) {
                return false;
            }

            if (! $nearshore) {
                return ! isset($blockedByLand[$cell->x.':'.$cell->y]);
            }
            $coordinate = new GridCoordinate((int) $cell->x, (int) $cell->y);
            $selectedDistance = $selectedLand->min(fn (MapCell $land): int => $coordinate->distanceTo(
                new GridCoordinate((int) $land->x, (int) $land->y),
            ));
            if ($selectedDistance !== (int) $settings['exact_owned_land_distance']) {
                return false;
            }
            $otherDistance = $otherLand->isEmpty() ? null : $otherLand->min(fn (MapCell $land): int => $coordinate->distanceTo(
                new GridCoordinate((int) $land->x, (int) $land->y),
            ));

            return $otherDistance === null || $otherDistance > (int) $settings['other_land_exclusion_distance'];
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
            'target_nation_id' => $targetNationId,
        ]);
        $metrics['world_sea_monsters_spawned'] = 1;

        return $metrics;
    }

    private function sea(): TerrainDefinition
    {
        return $this->sea ??= TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
    }
}
