<?php

namespace App\Application;

use App\Domain\Facility\FacilityRankPolicy;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Monster\MonsterDispatchOption;
use App\Domain\Monster\MonsterNaturalSpawnPolicy;
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
use Illuminate\Database\Eloquent\Collection;

final class MonsterSpawnService
{
    private ?TerrainDefinition $wasteland = null;

    public function __construct(
        private readonly NationLandAreaCalculator $landArea,
        private readonly MonsterNaturalSpawnPolicy $policy,
        private readonly MapCellStateService $cells,
        private readonly TurnEventRecorder $events,
        private readonly NationProtectionPolicy $nationProtection,
        private readonly SecretaryItemEffectAggregator $secretaryItems,
        private readonly FacilityRankPolicy $facilityRanks,
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
        $this->policy->validatePoolReferences($system, $definitions->keys()->all());

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

        $nationIds = $nations->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $populationRows = $nationIds === []
            ? collect()
            : MapCell::query()
                ->where('map_space_id', $space->id)
                ->whereIn('owner_nation_id', $nationIds)
                ->selectRaw('owner_nation_id, SUM(population) AS aggregate')
                ->groupBy('owner_nation_id')
                ->pluck('aggregate', 'owner_nation_id');
        /** @var array<int, int> $populationByNation */
        $populationByNation = [];
        /** @var list<int> $populationEligibleNationIds */
        $populationEligibleNationIds = [];
        foreach ($nations as $nation) {
            $population = (int) ($populationRows[$nation->id] ?? 0);
            $populationByNation[$nation->id] = $population;
            if ($population >= $minimumPopulation && $this->policy->poolForPopulation($system, $population) !== []) {
                $populationEligibleNationIds[] = $nation->id;
            }
        }
        if ($populationEligibleNationIds === []) {
            return $metrics;
        }

        $landByNation = $this->landArea->forNationIds($context->world, $populationEligibleNationIds);
        $rankTwoCondition = $this->rankTwoCondition($system, $definitions->keys()->all());
        $rankTwoNationIds = $rankTwoCondition === null
            ? []
            : $this->rankTwoNationIds(
                $context,
                $space,
                $populationEligibleNationIds,
                $rankTwoCondition['facility_keys'],
            );
        $centralFacilityLevels = $this->centralFacilityLevels(
            $context,
            $space,
            $populationEligibleNationIds,
        );
        $settlementKeys = $system['settlement_facility_keys'] ?? [];
        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $populationEligibleNationIds)
            ->where('population', '>', 0)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $settlementKeys))
            ->with('facility')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $occupied = MonsterOccupancy::query()
            ->whereIn('map_cell_id', $cells->pluck('id'))
            ->pluck('map_cell_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        /** @var array<int, list<MapCell>> $candidatesByNation */
        $candidatesByNation = [];
        foreach ($cells as $cell) {
            if ($cell->owner_nation_id === null) {
                continue;
            }
            if (! $occupied->has($cell->id)
                && ! $this->nationProtection->protects($context, $cell->x, $cell->y)) {
                $candidatesByNation[$cell->owner_nation_id][] = $cell;
            }
        }

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
            $itemPercent = $this->secretaryItems->snapshotPercentage(
                $context->state,
                (int) $nation->id,
                SecretaryItemGameplayContract::NATURAL_MONSTER_SPAWN_PERCENT,
                'normal_nation_natural_spawn',
            );
            $spawnProbability['numerator'] = min(
                $spawnProbability['denominator'],
                intdiv($spawnProbability['numerator'] * max(0, 100 + $itemPercent), 100),
            );
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
            $typeStream = $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'type', $streamVersion),
            );
            $typeIndex = $typeStream->integer(0, count($pool) - 1);
            $monsterKey = $pool[$typeIndex];
            if ($rankTwoCondition !== null
                && in_array($monsterKey, $rankTwoCondition['conditional_monster_keys'], true)
                && ! isset($rankTwoNationIds[$nation->id])) {
                $fallbackPool = $rankTwoCondition['fallback_monster_keys'];
                $monsterKey = $fallbackPool[$typeStream->integer(0, count($fallbackPool) - 1)];
            }
            /** @var MonsterDefinition|null $definition */
            $definition = $definitions->get($monsterKey);
            if ($definition === null || $definition->key === 'mecha_inora') {
                throw new DomainException('Natural spawn selected an invalid monster definition.');
            }
            $hp = $definition->base_hp + $context->random->stream(
                TurnRandomStreamFactory::monsterSpawn($nation->id, 'hp', $streamVersion),
            )->integer(0, $definition->hp_variation);
            $baseRandomHp = $hp;
            $centralLevel = $centralFacilityLevels[$nation->id] ?? 0;
            $hp = $this->applyCentralFacilityHpBonus(
                $context,
                (int) $nation->id,
                $hp,
                $centralLevel,
            );

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
                'base_random_hp' => $baseRandomHp,
                'central_facility_level_total' => $centralLevel,
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

    public function dispatch(
        TurnContext $context,
        Nation $target,
        int $queueItemId,
        MonsterDispatchOption $option,
    ): MonsterInstance {
        if ($target->world_id !== $context->world->id
            || ! in_array($target->state, ['active', 'dormant'], true)) {
            throw new DomainException('A dispatched monster requires a current target Nation in the current World.');
        }
        $candidates = $this->dispatchCandidates($context, $target, true);
        if ($candidates->isEmpty()) {
            throw new DomainException('A dispatched monster lost its eligible settlement before execution.');
        }
        $index = $context->random->stream(TurnRandomStreamFactory::monsterDispatch($queueItemId))
            ->integer(0, $candidates->count() - 1);
        /** @var MapCell $cell */
        $cell = $candidates->values()->get($index);
        if ($option->rulesetVersionId !== (int) $context->ruleset->id) {
            throw new DomainException('Monster dispatch option does not match the locked Turn ruleset.');
        }
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $context->ruleset->id)
            ->where('key', $option->monsterDefinitionKey)
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
            'dispatch_selector' => $option->selector,
            'dispatch_cost_money' => $option->costMoney,
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

        return $query->get()->reject(
            fn (MapCell $cell): bool => $this->nationProtection->protects($context, $cell->x, $cell->y),
        )->values();
    }

    private function wasteland(): TerrainDefinition
    {
        return $this->wasteland ??= TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $system
     * @param  list<string>  $definitionKeys
     * @return array{facility_keys: list<string>, conditional_monster_keys: list<string>, fallback_monster_keys: list<string>, fallback_selection: string}|null
     */
    private function rankTwoCondition(array $system, array $definitionKeys): ?array
    {
        $condition = $system['rank_two_condition'] ?? null;
        if ($condition === null) {
            return null;
        }
        if (! is_array($condition) || array_is_list($condition)) {
            throw new DomainException('The active ruleset has an invalid rank-two monster condition.');
        }
        $requiredKeys = ['facility_keys', 'conditional_monster_keys', 'fallback_monster_keys', 'fallback_selection'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $condition)) {
                throw new DomainException("The active ruleset rank-two monster condition is missing {$key}.");
            }
        }
        foreach (['facility_keys', 'conditional_monster_keys', 'fallback_monster_keys'] as $key) {
            if (! is_array($condition[$key]) || ! array_is_list($condition[$key]) || $condition[$key] === []) {
                throw new DomainException("The active ruleset rank-two monster condition has an invalid {$key}.");
            }
            foreach ($condition[$key] as $monsterKey) {
                if (! is_string($monsterKey) || $monsterKey === '') {
                    throw new DomainException("The active ruleset rank-two monster condition has an invalid {$key} entry.");
                }
                if ($key !== 'facility_keys' && ! in_array($monsterKey, $definitionKeys, true)) {
                    throw new DomainException("The active ruleset rank-two monster condition references missing definition {$monsterKey}.");
                }
            }
        }
        if ($condition['fallback_selection'] !== 'single_uniform_draw_no_retry') {
            throw new DomainException('The active ruleset rank-two monster fallback selection is unsupported.');
        }

        return [
            'facility_keys' => $condition['facility_keys'],
            'conditional_monster_keys' => $condition['conditional_monster_keys'],
            'fallback_monster_keys' => $condition['fallback_monster_keys'],
            'fallback_selection' => $condition['fallback_selection'],
        ];
    }

    /**
     * @param  list<int>  $nationIds
     * @param  list<string>  $facilityKeys
     * @return array<int, true>
     */
    private function rankTwoNationIds(
        TurnContext $context,
        MapSpace $space,
        array $nationIds,
        array $facilityKeys,
    ): array {
        if ($nationIds === []) {
            return [];
        }
        $rankTwoNationIds = [];
        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $nationIds)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $facilityKeys))
            ->with('facility')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($cells as $cell) {
            $ownerNationId = $cell->owner_nation_id;
            $facility = $cell->facility;
            if ($ownerNationId === null || $facility === null) {
                continue;
            }
            if ($this->facilityRanks->isRankTwo(
                $context->ruleset->settings,
                $facility->key,
                $cell->facility_scale,
            )) {
                $rankTwoNationIds[$ownerNationId] = true;
            }
        }

        return $rankTwoNationIds;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, int>
     */
    private function centralFacilityLevels(TurnContext $context, MapSpace $space, array $nationIds): array
    {
        $contract = $context->ruleset->settings['central_facilities']['natural_monster_hp'] ?? null;
        if ($contract === null) {
            return [];
        }
        $facilityKeys = is_array($contract) ? ($contract['facility_keys'] ?? null) : null;
        if (! is_array($contract)
            || ! is_array($facilityKeys)
            || ! array_is_list($facilityKeys)
            || $facilityKeys === []
            || array_filter($facilityKeys, static fn (mixed $key): bool => ! is_string($key) || $key === '') !== []
            || ($contract['level_aggregation'] ?? null) !== 'sum') {
            throw new DomainException('The active Ruleset has an invalid central-facility monster HP contract.');
        }

        $levels = [];
        $seen = [];
        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $nationIds)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $contract['facility_keys']))
            ->with('facility')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($cells as $cell) {
            $nationId = $cell->owner_nation_id;
            $facilityKey = $cell->facility?->key;
            $level = $cell->facility_scale;
            $maximumLevel = is_string($facilityKey)
                ? ($context->ruleset->settings['facility_definitions'][$facilityKey]['maximum_scale'] ?? null)
                : null;
            if (! is_int($nationId) || ! is_string($facilityKey) || ! is_int($level) || $level < 1
                || ! is_int($maximumLevel) || $level > $maximumLevel) {
                throw new DomainException('A central facility has invalid persisted level data.');
            }
            $identity = $nationId.':'.$facilityKey;
            if (isset($seen[$identity])) {
                throw new DomainException('A Nation has duplicate central facilities of one type.');
            }
            $seen[$identity] = true;
            $levels[$nationId] = ($levels[$nationId] ?? 0) + $level;
        }

        return $levels;
    }

    private function applyCentralFacilityHpBonus(
        TurnContext $context,
        int $nationId,
        int $baseHp,
        int $level,
    ): int {
        if ($level === 0) {
            return $baseHp;
        }
        $contract = $context->ruleset->settings['central_facilities']['natural_monster_hp'] ?? null;
        $percentPerLevel = is_array($contract) ? ($contract['percent_per_level'] ?? null) : null;
        $denominator = is_array($contract) ? ($contract['draw_denominator'] ?? null) : null;
        if (! is_array($contract)
            || ! is_int($percentPerLevel)
            || $percentPerLevel < 0
            || ($contract['rounding'] ?? null) !== 'independent_fractional_draw'
            || ! is_int($denominator)
            || $denominator < 1
            || $denominator > 2_147_483_648
            || ! is_int($contract['stream_version'] ?? null)
            || $contract['stream_version'] < 1) {
            throw new DomainException('The active Ruleset has invalid central-facility monster HP arithmetic.');
        }
        if ($percentPerLevel > 0 && $level > intdiv(PHP_INT_MAX - $denominator, $percentPerLevel)) {
            throw new DomainException('Central-facility monster HP factor would overflow.');
        }
        $factor = $denominator + ($level * $percentPerLevel);
        if ($baseHp > intdiv(PHP_INT_MAX, $factor)) {
            throw new DomainException('Central-facility monster HP would overflow.');
        }
        $numerator = $baseHp * $factor;
        $hp = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if ($remainder > 0) {
            $draw = $context->random->stream(TurnRandomStreamFactory::monsterSpawn(
                $nationId,
                'hp_fraction',
                $contract['stream_version'],
            ))->integer(1, $denominator);
            if ($draw <= $remainder) {
                $hp++;
            }
        }

        return $hp;
    }
}
