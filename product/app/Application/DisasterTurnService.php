<?php

namespace App\Application;

use App\Domain\Disaster\LandSubsidenceThresholdResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\TerrainDefinition;
use DomainException;

final class DisasterTurnService
{
    public function __construct(
        private readonly MapCellStateService $cells,
        private readonly TurnEventRecorder $events,
        private readonly NationLandAreaCalculator $landArea,
        private readonly LandSubsidenceThresholdResolver $subsidenceThreshold,
        private readonly MonsterRemovalService $monsterRemoval,
        private readonly MonsterSpawnService $monsterSpawn,
    ) {}

    /** @return array<string, int> */
    public function executeGlobal(TurnContext $context): array
    {
        $this->monsterRemoval->beginWorld($context);
        $rules = $this->rules($context);
        $metrics = [
            'executed_disasters' => 0,
            'damaged_cells' => 0,
            'land_subsidence_nations' => 0,
            'land_subsidence_changed_to_sea' => 0,
            'land_subsidence_changed_to_shallow' => 0,
            'land_subsidence_protected_mountains' => 0,
            'land_subsidence_capitals_damaged' => 0,
            'land_subsidence_affected_chunks' => 0,
            'eligible_spawn_nations' => 0,
            'spawn_draws' => 0,
            'monsters_spawned' => 0,
            'blocked_no_settlement' => 0,
            'monsters_removed_by_terrain' => 0,
        ];
        $space = $this->surfaceSpace($context);

        $definitions = [
            'earthquake' => [TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_TRIGGER, TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER],
            'tsunami' => [TurnRandomStreamFactory::GLOBAL_TSUNAMI_TRIGGER, TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER],
            'typhoon' => [TurnRandomStreamFactory::GLOBAL_TYPHOON_TRIGGER, TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER],
            'meteor_shower' => [TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_TRIGGER, TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER],
            'huge_meteor' => [TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_TRIGGER, TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER],
            'eruption' => [TurnRandomStreamFactory::GLOBAL_ERUPTION_TRIGGER, TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER],
        ];

        $chunkCount = $space->currentBounds()->chunkCount();
        $scaleNumerator = 16 * $chunkCount;
        $fullOpportunities = intdiv($scaleNumerator, 225);
        $fractionalNumerator = $scaleNumerator % 225;

        foreach ($definitions as $key => [$triggerLabel, $centerLabel]) {
            $settings = $rules[$key];
            $opportunities = $fullOpportunities;
            $fractionalGateDraw = null;
            if ($fractionalNumerator > 0) {
                $fractionalGateDraw = $context->random
                    ->stream(TurnRandomStreamFactory::worldDisasterAreaFraction($key))
                    ->integer(0, 224);
                if ($fractionalGateDraw < $fractionalNumerator) {
                    $opportunities++;
                }
            }

            for ($opportunity = 1; $opportunity <= $opportunities; $opportunity++) {
                $trigger = $this->probabilityDraw($context, $settings['probability'], $triggerLabel);
                if (! $trigger['success']) {
                    continue;
                }
                $center = $this->center($context, $space, $settings['center_padding'], $centerLabel);
                $isFractional = $opportunity > $fullOpportunities;
                $this->events->record($context, 'disaster.triggered', $context->world, [
                    'disaster_key' => $key,
                    'center_x' => $center->x,
                    'center_y' => $center->y,
                    'draw' => $trigger['draw'],
                    'numerator' => $settings['probability']['numerator'],
                    'denominator' => $settings['probability']['denominator'],
                    'world_chunk_count' => $chunkCount,
                    'world_scale_numerator' => $scaleNumerator,
                    'world_scale_denominator' => 225,
                    'world_opportunity_index' => $opportunity,
                    'world_opportunity_kind' => $isFractional ? 'fractional' : 'integer',
                    'world_fractional_gate_draw' => $isFractional ? $fractionalGateDraw : null,
                    'world_fractional_gate_numerator' => $fractionalNumerator,
                ]);
                $metrics['executed_disasters']++;
                $metrics['damaged_cells'] += match ($key) {
                    'earthquake' => $this->earthquake(
                        $context,
                        $space,
                        $center,
                        $settings,
                        TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_EFFECT,
                        'global',
                    ),
                    'tsunami' => $this->tsunami($context, $space, $center, $settings),
                    'typhoon' => $this->typhoon($context, $space, $center, $settings),
                    'meteor_shower' => $this->meteorShower($context, $space, $center, $settings),
                    'huge_meteor' => $this->resolveHugeMeteorBlast($context, $space, $center, $settings),
                    'eruption' => $this->eruption($context, $space, $center, $settings),
                };
            }
        }

        $subsidence = $this->landSubsidence($context, $space, $rules['land_subsidence'] ?? null);
        $metrics['executed_disasters'] += $subsidence['triggered_nations'];
        $metrics['damaged_cells'] += $subsidence['changed_to_sea']
            + $subsidence['changed_to_shallow']
            + $subsidence['capitals_damaged'];
        $metrics['land_subsidence_nations'] = $subsidence['triggered_nations'];
        $metrics['land_subsidence_changed_to_sea'] = $subsidence['changed_to_sea'];
        $metrics['land_subsidence_changed_to_shallow'] = $subsidence['changed_to_shallow'];
        $metrics['land_subsidence_protected_mountains'] = $subsidence['protected_mountains'];
        $metrics['land_subsidence_capitals_damaged'] = $subsidence['capitals_damaged'];
        $metrics['land_subsidence_affected_chunks'] = $subsidence['affected_chunks'];

        foreach ($this->monsterSpawn->spawnNatural($context, $space) as $key => $value) {
            $metrics[$key] = $value;
        }
        $metrics['monsters_removed_by_terrain'] = $this->monsterRemoval->removedCount();

        return $metrics;
    }

    /**
     * @return array{
     *     triggered_nations: int,
     *     changed_to_sea: int,
     *     changed_to_shallow: int,
     *     protected_mountains: int,
     *     capitals_damaged: int,
     *     affected_chunks: int
     * }
     */
    private function landSubsidence(TurnContext $context, MapSpace $space, mixed $authoredSettings): array
    {
        $settings = $this->landSubsidenceSettings($authoredSettings);
        $empty = [
            'triggered_nations' => 0,
            'changed_to_sea' => 0,
            'changed_to_shallow' => 0,
            'protected_mountains' => 0,
            'capitals_damaged' => 0,
            'affected_chunks' => 0,
        ];
        if (! $settings['enabled']) {
            return $empty;
        }

        $nations = Nation::query()
            ->where('world_id', $context->world->id)
            ->where('state', 'active')
            ->orderBy('id')
            ->get();
        $landByNation = $this->landArea->forNationIds(
            $context->world,
            $nations->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        /** @var array<int, array{nation: Nation, owned_land_cells: int, threshold: int, draw: int, to_sea: array<int, true>, to_shallow: array<int, true>, protected_mountains: array<int, true>, capitals: array<int, true>}> $plans */
        $plans = [];
        foreach ($nations as $nation) {
            $ownedLandCells = $landByNation[$nation->id] ?? 0;
            $threshold = $this->subsidenceThreshold->resolve($context->ruleset, $nation);
            if ($ownedLandCells <= $threshold) {
                continue;
            }
            $trigger = $this->probabilityDraw(
                $context,
                $settings['probability'],
                TurnRandomStreamFactory::landSubsidenceTrigger($nation->id, $settings['stream_version']),
            );
            if (! $trigger['success']) {
                continue;
            }
            $plans[$nation->id] = [
                'nation' => $nation,
                'owned_land_cells' => $ownedLandCells,
                'threshold' => $threshold,
                'draw' => $trigger['draw'],
                'to_sea' => [],
                'to_shallow' => [],
                'protected_mountains' => [],
                'capitals' => [],
            ];
        }
        if ($plans === []) {
            return $empty;
        }

        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->orderBy('id')->lockForUpdate()->with(['terrain', 'facility'])->get();
        /** @var array<int, MapCell> $cellsById */
        $cellsById = [];
        /** @var array<string, array{id: int, x: int, y: int, map_chunk_id: int, terrain_key: string, facility_key: string|null, owner_nation_id: int|null, population: int}> $snapshot */
        $snapshot = [];
        foreach ($cells as $cell) {
            $cellsById[$cell->id] = $cell;
            $snapshot[$cell->x.':'.$cell->y] = [
                'id' => $cell->id,
                'x' => $cell->x,
                'y' => $cell->y,
                'map_chunk_id' => $cell->map_chunk_id,
                'terrain_key' => $cell->terrain->key,
                'facility_key' => $cell->facility?->key,
                'owner_nation_id' => $cell->owner_nation_id,
                'population' => $cell->population,
            ];
        }

        foreach ($plans as $nationId => &$plan) {
            foreach ($snapshot as $cellSnapshot) {
                if ($cellSnapshot['owner_nation_id'] !== $nationId
                    || in_array($cellSnapshot['terrain_key'], ['sea', 'shallow'], true)) {
                    continue;
                }

                $coastal = false;
                $origin = new GridCoordinate($cellSnapshot['x'], $cellSnapshot['y']);
                foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
                    $coordinate = $origin->neighbor($direction);
                    if ($coordinate->x < $space->min_x || $coordinate->x > $space->max_x
                        || $coordinate->y < $space->min_y || $coordinate->y > $space->max_y) {
                        $coastal = true;

                        continue;
                    }
                    $neighbor = $snapshot[$coordinate->x.':'.$coordinate->y] ?? null;
                    if ($neighbor === null || ! in_array($neighbor['terrain_key'], ['sea', 'shallow'], true)) {
                        continue;
                    }
                    $coastal = true;
                    if ($neighbor['terrain_key'] === 'shallow'
                        && in_array($neighbor['owner_nation_id'], [null, $nationId], true)) {
                        $plan['to_sea'][$neighbor['id']] = true;
                    }
                }
                if (! $coastal) {
                    continue;
                }
                if ($cellSnapshot['terrain_key'] === 'mountain') {
                    $plan['protected_mountains'][$cellSnapshot['id']] = true;
                } elseif ($cellSnapshot['facility_key'] === 'capital') {
                    $plan['capitals'][$cellSnapshot['id']] = true;
                } else {
                    $plan['to_shallow'][$cellSnapshot['id']] = true;
                }
            }
        }
        unset($plan);

        /** @var array<int, array<int, true>> $seaAffectedNations */
        $seaAffectedNations = [];
        foreach ($plans as $nationId => $plan) {
            foreach (array_keys($plan['to_sea']) as $cellId) {
                $seaAffectedNations[$cellId][$nationId] = true;
            }
        }

        $changedToSea = 0;
        $seaCellIds = array_keys($seaAffectedNations);
        sort($seaCellIds, SORT_NUMERIC);
        foreach ($seaCellIds as $cellId) {
            $affectedNationIds = array_map('intval', array_keys($seaAffectedNations[$cellId]));
            sort($affectedNationIds, SORT_NUMERIC);
            if ($this->changeCell(
                $context,
                $cellsById[$cellId],
                'land_subsidence',
                $settings['affected_shallow_result'],
                true,
                'disaster.cell_damaged',
                ['source' => 'land_subsidence', 'affected_nation_ids' => $affectedNationIds],
            )) {
                $changedToSea++;
            }
        }

        $changedToShallow = 0;
        $protectedMountains = 0;
        $capitalsDamaged = 0;
        /** @var array<int, true> $affectedChunks */
        $affectedChunks = [];
        /** @var array<int, list<array{before_population: int, after_population: int, damage_percent: int}>> $capitalDamageByNation */
        $capitalDamageByNation = [];
        foreach ($plans as $nationId => $plan) {
            $protectedMountains += count($plan['protected_mountains']);
            $landCellIds = array_keys($plan['to_shallow']);
            sort($landCellIds, SORT_NUMERIC);
            foreach ($landCellIds as $cellId) {
                if ($this->changeCell(
                    $context,
                    $cellsById[$cellId],
                    'land_subsidence',
                    $settings['affected_coastal_land_result'],
                    true,
                    'disaster.cell_damaged',
                    ['source' => 'land_subsidence'],
                )) {
                    $changedToShallow++;
                }
            }
            $capitalCellIds = array_keys($plan['capitals']);
            sort($capitalCellIds, SORT_NUMERIC);
            foreach ($capitalCellIds as $cellId) {
                $capitalDamageByNation[$nationId][] = $this->damageCapitalByPercentage(
                    $context,
                    $cellsById[$cellId],
                    'land_subsidence',
                    $settings['capital_damage_percentage'],
                    ['source' => 'land_subsidence'],
                );
                $capitalsDamaged++;
            }
            foreach ([...array_keys($plan['to_sea']), ...$landCellIds, ...$capitalCellIds] as $cellId) {
                $affectedChunks[$snapshot[$cellsById[$cellId]->x.':'.$cellsById[$cellId]->y]['map_chunk_id']] = true;
            }
        }

        foreach ($plans as $nationId => $plan) {
            $nationChunkIds = [];
            foreach ([...array_keys($plan['to_sea']), ...array_keys($plan['to_shallow']), ...array_keys($plan['capitals'])] as $cellId) {
                $nationChunkIds[$cellsById[$cellId]->map_chunk_id] = true;
            }
            $this->events->record($context, 'land_subsidence.triggered', $plan['nation'], [
                'disaster_key' => 'land_subsidence',
                'nation_id' => $nationId,
                'nation_number' => $plan['nation']->nation_number,
                'owned_land_cells_before' => $plan['owned_land_cells'],
                'effective_safe_land_cells' => $plan['threshold'],
                'changed_to_sea_count' => count($plan['to_sea']),
                'changed_to_shallow_count' => count($plan['to_shallow']),
                'protected_mountain_count' => count($plan['protected_mountains']),
                'capital_damage' => $capitalDamageByNation[$nationId] ?? [],
                'affected_chunk_count' => count($nationChunkIds),
                'draw' => $plan['draw'],
                'numerator' => $settings['probability']['numerator'],
                'denominator' => $settings['probability']['denominator'],
                'snapshot_applied' => true,
            ]);
        }

        return [
            'triggered_nations' => count($plans),
            'changed_to_sea' => $changedToSea,
            'changed_to_shallow' => $changedToShallow,
            'protected_mountains' => $protectedMountains,
            'capitals_damaged' => $capitalsDamaged,
            'affected_chunks' => count($affectedChunks),
        ];
    }

    public function landLevelEarthquake(
        TurnContext $context,
        NationCommandQueueItem $item,
        int $x,
        int $y,
    ): bool {
        $settings = $context->ruleset->settings['turn_processing']['command_random_effects']['land_level_earthquake'] ?? null;
        if (! is_array($settings)) {
            throw new DomainException('The active ruleset is missing land_level earthquake settings.');
        }
        $trigger = $this->probabilityDraw(
            $context,
            $settings['probability'],
            TurnRandomStreamFactory::LAND_LEVEL_EARTHQUAKE_TRIGGER,
        );
        if (! $trigger['success']) {
            return false;
        }

        $this->events->record($context, 'command.land_level_earthquake', $item, [
            'nation_id' => $item->queue()->value('nation_id'),
            'command_key' => 'land_level',
            'disaster_key' => 'earthquake',
            'center_x' => $x,
            'center_y' => $y,
            'draw' => $trigger['draw'],
            'numerator' => $settings['probability']['numerator'],
            'denominator' => $settings['probability']['denominator'],
        ]);
        $this->earthquake(
            $context,
            $this->surfaceSpace($context),
            new GridCoordinate($x, $y),
            $settings,
            TurnRandomStreamFactory::LAND_LEVEL_EARTHQUAKE_EFFECT,
            'land_level',
        );

        return true;
    }

    public function processFire(TurnContext $context, MapCell $cell): bool
    {
        if ($this->monsterRemoval->hasAtCell($cell->id)) {
            return false;
        }
        $rules = $this->rules($context);
        $settings = $rules['fire'];
        $facilityKey = $cell->facility?->key;
        $settlement = in_array($facilityKey, ['village', 'town', 'city', 'capital'], true)
            && $cell->population >= $settings['minimum_city_population'];
        if (! $settlement && ! in_array($facilityKey, $settings['facility_keys'], true)) {
            return false;
        }

        $protection = $this->adjacentProtectionCount(
            $cell,
            $settings['protection_facility_keys'],
        );
        if ($protection > 0) {
            $this->events->record($context, 'fire.prevented', $cell, [
                'nation_id' => $cell->owner_nation_id,
                'disaster_key' => 'fire',
                'x' => $cell->x,
                'y' => $cell->y,
                'protection_count' => $protection,
            ]);

            return false;
        }

        $trigger = $this->probabilityDraw($context, $settings['probability'], TurnRandomStreamFactory::FIRE);
        if (! $trigger['success']) {
            return false;
        }
        if ($this->isCapital($cell)) {
            $this->damageCapital($context, $cell, 'fire', 'facility_or_wasteland', [
                'draw' => $trigger['draw'],
                'source' => 'process_cells',
            ]);

            return true;
        }

        $this->changeCell($context, $cell, 'fire', 'wasteland', false, 'fire.damaged', [
            'draw' => $trigger['draw'],
            'numerator' => $settings['probability']['numerator'],
            'denominator' => $settings['probability']['denominator'],
        ]);

        return true;
    }

    /** @param array<string, mixed> $settings */
    private function earthquake(
        TurnContext $context,
        MapSpace $space,
        GridCoordinate $center,
        array $settings,
        string $effectLabel,
        string $source,
    ): int {
        $damaged = 0;
        foreach ($center->radius($settings['radius']) as $coordinate) {
            $cell = $this->cellAt($space, $coordinate);
            if ($cell === null || ! $this->isMutable($cell)) {
                continue;
            }
            if ($this->monsterRemoval->hasAtCell($cell->id)) {
                continue;
            }
            $facilityKey = $cell->facility?->key;
            $city = in_array($facilityKey, ['village', 'town', 'city', 'capital'], true)
                && $cell->population >= $settings['minimum_city_population'];
            if (! $city && ! in_array($facilityKey, $settings['facility_keys'], true)) {
                continue;
            }
            $draw = $this->probabilityDraw($context, $settings['damage_probability'], $effectLabel);
            if (! $draw['success']) {
                continue;
            }
            if ($this->isCapital($cell)) {
                $this->damageCapital($context, $cell, 'earthquake', 'facility_or_wasteland', [
                    'source' => $source,
                    'center_x' => $center->x,
                    'center_y' => $center->y,
                    'draw' => $draw['draw'],
                ]);
            } else {
                $this->changeCell($context, $cell, 'earthquake', 'wasteland', false, 'disaster.cell_damaged', [
                    'source' => $source,
                    'center_x' => $center->x,
                    'center_y' => $center->y,
                    'draw' => $draw['draw'],
                ]);
            }
            $damaged++;
        }

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    private function tsunami(TurnContext $context, MapSpace $space, GridCoordinate $center, array $settings): int
    {
        $damaged = 0;
        foreach ($center->radius($settings['radius']) as $coordinate) {
            $cell = $this->cellAt($space, $coordinate);
            if ($cell === null || ! $this->isMutable($cell) || ! $this->isTsunamiTarget($cell, $settings)) {
                continue;
            }
            if ($this->monsterRemoval->hasAtCell($cell->id)) {
                continue;
            }
            $water = $this->adjacentWaterCount($cell, $space, $settings['water_facility_keys']);
            $draw = $context->random->stream(TurnRandomStreamFactory::GLOBAL_TSUNAMI_EFFECT)
                ->integer(0, $settings['internal_denominator'] - 1);
            if ($draw >= max(0, $water - $settings['adjacent_water_offset'])) {
                continue;
            }
            if ($this->isCapital($cell)) {
                $this->damageCapital($context, $cell, 'tsunami', 'facility_or_wasteland', [
                    'center_x' => $center->x, 'center_y' => $center->y,
                    'adjacent_water_count' => $water, 'draw' => $draw,
                ]);
            } else {
                $this->changeCell($context, $cell, 'tsunami', 'wasteland', false, 'disaster.cell_damaged', [
                    'center_x' => $center->x, 'center_y' => $center->y,
                    'adjacent_water_count' => $water, 'draw' => $draw,
                ]);
            }
            $damaged++;
        }

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    private function typhoon(TurnContext $context, MapSpace $space, GridCoordinate $center, array $settings): int
    {
        $damaged = 0;
        foreach ($center->radius($settings['radius']) as $coordinate) {
            $cell = $this->cellAt($space, $coordinate);
            if ($cell === null || ! $this->isMutable($cell)
                || ! in_array($cell->facility?->key, $settings['facility_keys'], true)) {
                continue;
            }
            if ($this->monsterRemoval->hasAtCell($cell->id)) {
                continue;
            }
            $protection = $this->adjacentProtectionCount($cell, $settings['protection_facility_keys']);
            $draw = $context->random->stream(TurnRandomStreamFactory::GLOBAL_TYPHOON_EFFECT)
                ->integer(0, $settings['internal_denominator'] - 1);
            if ($draw >= max(0, $settings['base_damage_threshold'] - $protection)) {
                continue;
            }
            $this->changeCell($context, $cell, 'typhoon', 'plain', false, 'disaster.cell_damaged', [
                'center_x' => $center->x, 'center_y' => $center->y,
                'protection_count' => $protection, 'draw' => $draw,
            ]);
            $damaged++;
        }

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    private function meteorShower(TurnContext $context, MapSpace $space, GridCoordinate $center, array $settings): int
    {
        $damaged = 0;
        $coordinates = $center->radius($settings['radius']);
        $stream = $context->random->stream(TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_EFFECT);
        do {
            $coordinate = $coordinates[$stream->integer(0, count($coordinates) - 1)];
            $cell = $this->cellAt($space, $coordinate);
            if ($cell !== null && $this->isMutable($cell)) {
                if ($this->isCapital($cell)) {
                    $this->damageCapital($context, $cell, 'meteor_shower', 'deep_sea', [
                        'center_x' => $center->x, 'center_y' => $center->y,
                    ]);
                    $damaged++;
                } elseif ($cell->terrain->key === 'shallow') {
                    if ($this->changeCell($context, $cell, 'meteor_shower', 'sea', false, 'disaster.cell_damaged')) {
                        $damaged++;
                    }
                } elseif ($cell->terrain->key !== 'sea'
                    || in_array($cell->facility?->key, $settings['seabed_facility_keys'], true)) {
                    if ($this->changeCell($context, $cell, 'meteor_shower', 'sea', true, 'disaster.cell_damaged')) {
                        $damaged++;
                    }
                }
            }
            $continuation = $settings['continuation_probability'];
            $continueDraw = $stream->integer(0, $continuation['denominator'] - 1);
        } while ($continueDraw < $continuation['numerator']);

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    public function resolveHugeMeteorBlast(
        TurnContext $context,
        MapSpace $space,
        GridCoordinate $center,
        array $settings,
        string $disasterKey = 'huge_meteor',
    ): int {
        $damaged = 0;
        $coordinates = [...$center->ring(0), ...$center->ring(1), ...$center->ring(2)];
        foreach ($coordinates as $coordinate) {
            $cell = $this->cellAt($space, $coordinate);
            if ($cell === null || ! $this->isMutable($cell)) {
                continue;
            }
            $distance = $center->distanceTo($coordinate);
            $monsterRemoved = false;
            if ($this->isCapital($cell)) {
                if ($distance === 0) {
                    $this->damageCapital($context, $cell, $disasterKey, 'deep_sea');
                } elseif ($distance === 1) {
                    $this->damageCapital($context, $cell, $disasterKey, 'excavation_or_shallow');
                } elseif ($this->hugeMeteorRingTwoTarget($cell, $settings)) {
                    $this->damageCapital($context, $cell, $disasterKey, 'facility_or_wasteland');
                } else {
                    continue;
                }
                $damaged++;

                continue;
            }
            if ($distance === 2) {
                $monsterRemoved = $this->removeMonsterForTerrainEvent($context, $cell, $disasterKey);
                if (! $this->hugeMeteorRingTwoTarget($cell, $settings)) {
                    $damaged += $monsterRemoved ? 1 : 0;

                    continue;
                }
                $changed = $this->changeCell($context, $cell, $disasterKey, 'wasteland', false, 'disaster.cell_damaged');
            } elseif ($cell->terrain->key === 'sea' || $cell->terrain->key === 'shallow'
                || in_array($cell->facility?->key, $settings['seabed_facility_keys'], true)) {
                $changed = $this->changeCell($context, $cell, $disasterKey, 'sea', true, 'disaster.cell_damaged');
            } else {
                $changed = $this->changeCell(
                    $context,
                    $cell,
                    $disasterKey,
                    $distance === 0 ? 'sea' : 'shallow',
                    true,
                    'disaster.cell_damaged',
                );
            }
            $damaged += ($changed || $monsterRemoved) ? 1 : 0;
        }

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    private function eruption(TurnContext $context, MapSpace $space, GridCoordinate $center, array $settings): int
    {
        $damaged = 0;
        $centerCell = $this->cellAt($space, $center);
        if ($centerCell !== null && $this->isMutable($centerCell)) {
            if ($this->isCapital($centerCell)) {
                $this->damageCapital($context, $centerCell, 'eruption', 'eruption_center');
                $damaged++;
            } elseif ($this->changeCell($context, $centerCell, 'eruption', 'mountain', false, 'disaster.cell_damaged')) {
                $damaged++;
            }
        }

        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $cell = $this->cellAt($space, $center->neighbor($direction));
            if ($cell === null || ! $this->isMutable($cell) || $cell->terrain->key === 'mountain') {
                continue;
            }
            if ($this->isCapital($cell)) {
                $severity = $cell->terrain->key === 'sea'
                    ? 'excavation_or_shallow'
                    : 'facility_or_wasteland';
                $this->damageCapital($context, $cell, 'eruption', $severity, ['direction' => $direction]);
                $damaged++;

                continue;
            }
            $target = $cell->terrain->key === 'sea' ? 'shallow' : 'wasteland';
            if ($this->changeCell($context, $cell, 'eruption', $target, false, 'disaster.cell_damaged', [
                'direction' => $direction,
            ])) {
                $damaged++;
            }
        }

        return $damaged;
    }

    /** @param array<string, mixed> $settings */
    private function isTsunamiTarget(MapCell $cell, array $settings): bool
    {
        if (in_array($cell->terrain->key, ['sea', 'shallow', 'wasteland', 'forest', 'mountain'], true)) {
            return false;
        }
        $key = $cell->facility?->key;
        if (in_array($key, $settings['excluded_facility_keys'], true)) {
            return false;
        }
        if (in_array($key, $settings['settlement_facility_keys'], true)) {
            return $cell->population > 0;
        }

        return in_array($key, $settings['facility_keys'], true);
    }

    /** @param array<string, mixed> $settings */
    private function hugeMeteorRingTwoTarget(MapCell $cell, array $settings): bool
    {
        return ! in_array($cell->terrain->key, ['sea', 'shallow', 'wasteland', 'mountain'], true)
            && ! in_array($cell->facility?->key, $settings['seabed_facility_keys'], true);
    }

    /** @param list<string> $seabedFacilityKeys */
    private function adjacentWaterCount(MapCell $cell, MapSpace $space, array $seabedFacilityKeys): int
    {
        $count = 0;
        $origin = new GridCoordinate($cell->x, $cell->y);
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $coordinate = $origin->neighbor($direction);
            if ($coordinate->x < $space->min_x || $coordinate->x > $space->max_x
                || $coordinate->y < $space->min_y || $coordinate->y > $space->max_y) {
                $count++;

                continue;
            }
            $neighbor = $this->cellAt($space, $coordinate);
            if ($neighbor !== null && (in_array($neighbor->terrain->key, ['sea', 'shallow'], true)
                || in_array($neighbor->facility?->key, $seabedFacilityKeys, true))) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<string> $facilityKeys */
    private function adjacentProtectionCount(MapCell $cell, array $facilityKeys): int
    {
        $count = 0;
        $origin = new GridCoordinate($cell->x, $cell->y);
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $coordinate = $origin->neighbor($direction);
            $neighbor = MapCell::query()->where('map_space_id', $cell->map_space_id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)
                ->with(['terrain', 'facility'])->first();
            if ($neighbor !== null && ($neighbor->terrain->key === 'forest'
                || in_array($neighbor->facility?->key, $facilityKeys, true))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{before_population: int, after_population: int, damage_percent: int}
     */
    private function damageCapital(
        TurnContext $context,
        MapCell $cell,
        string $disasterKey,
        string $percentageKey,
        array $extra = [],
    ): array {
        $percentages = $context->ruleset->settings['capital_damage_percentages'] ?? null;
        if (! is_array($percentages) || ! is_int($percentages[$percentageKey] ?? null)) {
            throw new DomainException('The active ruleset has invalid Capital damage settings.');
        }

        return $this->damageCapitalByPercentage(
            $context,
            $cell,
            $disasterKey,
            $percentages[$percentageKey],
            $extra,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{before_population: int, after_population: int, damage_percent: int}
     */
    private function damageCapitalByPercentage(
        TurnContext $context,
        MapCell $cell,
        string $disasterKey,
        int $percentage,
        array $extra = [],
    ): array {
        $minimum = $context->ruleset->settings['capital_minimum_population'] ?? null;
        if (! is_int($minimum) || $minimum < 1 || $percentage < 0 || $percentage > 100) {
            throw new DomainException('The active ruleset has invalid Capital damage settings.');
        }
        $before = $cell->population;
        $cell->population = max($minimum, intdiv($before * (100 - $percentage), 100));
        $minimumPopulationAdjustment = max(0, $cell->population - $before);
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'capital.disaster_damaged', $cell, [
            'nation_id' => $cell->owner_nation_id,
            'disaster_key' => $disasterKey,
            'x' => $cell->x,
            'y' => $cell->y,
            'damage_percent' => $percentage,
            'before_population' => $before,
            'after_population' => $cell->population,
            'minimum_population' => $minimum,
            'minimum_population_applied' => $minimumPopulationAdjustment > 0,
            'minimum_population_adjustment' => $minimumPopulationAdjustment,
            'capital_identity_preserved' => true,
            ...$extra,
        ]);

        return [
            'before_population' => $before,
            'after_population' => $cell->population,
            'damage_percent' => $percentage,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function changeCell(
        TurnContext $context,
        MapCell $cell,
        string $disasterKey,
        string $terrainKey,
        bool $neutralizeOwner,
        string $eventType,
        array $extra = [],
    ): bool {
        $beforeTerrain = $cell->terrain->key;
        $beforeFacility = $cell->facility?->key;
        $beforeOwner = $cell->owner_nation_id;
        $beforePopulation = $cell->population;
        $targetOwner = $neutralizeOwner ? null : $beforeOwner;
        $monsterRemoved = $this->removeMonsterForTerrainEvent($context, $cell, $disasterKey);
        if ($beforeTerrain === $terrainKey && $beforeFacility === null
            && $beforeOwner === $targetOwner && $beforePopulation === 0) {
            return $monsterRemoved;
        }

        $this->cells->setFacility($cell, null);
        $terrain = TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail();
        $this->cells->transitionTerrain($cell, $terrain);
        $cell->owner_nation_id = $targetOwner;
        $cell->population = 0;
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, $eventType, $cell, [
            'nation_id' => $beforeOwner,
            'disaster_key' => $disasterKey,
            'x' => $cell->x,
            'y' => $cell->y,
            'from_terrain_key' => $beforeTerrain,
            'to_terrain_key' => $terrainKey,
            'removed_facility_key' => $beforeFacility,
            'from_owner_nation_id' => $beforeOwner,
            'to_owner_nation_id' => $targetOwner,
            'before_population' => $beforePopulation,
            'after_population' => 0,
            ...$extra,
        ]);

        return true;
    }

    private function removeMonsterForTerrainEvent(
        TurnContext $context,
        MapCell $cell,
        string $disasterKey,
    ): bool {
        return $this->monsterRemoval->removeAtCell(
            $context,
            $cell,
            $disasterKey,
            'monster.removed_by_terrain_event',
            ['terrain_event_key' => $disasterKey, 'hardening_ignored' => true],
        );
    }

    private function saveChangedCell(TurnContext $context, MapCell $cell): void
    {
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
    }

    private function isCapital(MapCell $cell): bool
    {
        return $cell->facility?->key === 'capital';
    }

    private function isMutable(MapCell $cell): bool
    {
        return $cell->owner_nation_id === null
            || Nation::query()->whereKey($cell->owner_nation_id)->where('state', 'active')->exists();
    }

    private function surfaceSpace(TurnContext $context): MapSpace
    {
        return MapSpace::query()->where('world_id', $context->world->id)
            ->where('key', 'surface')->firstOrFail();
    }

    private function cellAt(MapSpace $space, GridCoordinate $coordinate): ?MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->lockForUpdate()->with(['terrain', 'facility'])->first();
    }

    /** @return array<string, array<string, mixed>> */
    private function rules(TurnContext $context): array
    {
        $turnProcessing = $context->ruleset->settings['turn_processing'] ?? null;
        if (! is_array($turnProcessing) || ! array_key_exists('disasters', $turnProcessing)) {
            throw new DomainException('The active ruleset is missing disaster settings.');
        }
        $rules = $turnProcessing['disasters'];
        if (! is_array($rules)) {
            throw new DomainException('The active ruleset is missing disaster settings.');
        }

        return $rules;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     base_safe_land_cells: int,
     *     probability: array{numerator: int, denominator: int},
     *     affected_shallow_result: string,
     *     affected_coastal_land_result: string,
     *     mountain_immune: bool,
     *     capital_damage_percentage: int,
     *     out_of_bounds_is_water: bool,
     *     stream_version: int
     * }
     */
    private function landSubsidenceSettings(mixed $authored): array
    {
        if (! is_array($authored)
            || ! is_bool($authored['enabled'] ?? null)
            || ! is_int($authored['base_safe_land_cells'] ?? null)
            || ! is_array($authored['probability'] ?? null)
            || ! is_int($authored['probability']['numerator'] ?? null)
            || ! is_int($authored['probability']['denominator'] ?? null)
            || ! is_string($authored['affected_shallow_result'] ?? null)
            || ! is_string($authored['affected_coastal_land_result'] ?? null)
            || ! is_bool($authored['mountain_immune'] ?? null)
            || ! is_int($authored['capital_damage_percentage'] ?? null)
            || ! is_bool($authored['out_of_bounds_is_water'] ?? null)
            || ! is_int($authored['stream_version'] ?? null)) {
            throw new DomainException('The active ruleset is missing land-subsidence settings.');
        }
        $numerator = $authored['probability']['numerator'];
        $denominator = $authored['probability']['denominator'];
        if ($authored['base_safe_land_cells'] < 0
            || $numerator < 0 || $denominator < 1 || $numerator > $denominator
            || $authored['affected_shallow_result'] !== 'sea'
            || $authored['affected_coastal_land_result'] !== 'shallow'
            || $authored['mountain_immune'] !== true
            || $authored['capital_damage_percentage'] < 0
            || $authored['capital_damage_percentage'] > 100
            || $authored['out_of_bounds_is_water'] !== true
            || $authored['stream_version'] < 1) {
            throw new DomainException('The active ruleset has invalid land-subsidence settings.');
        }

        return $authored;
    }

    /** @param array{numerator: int, denominator: int} $probability
     * @return array{draw: int, success: bool}
     */
    private function probabilityDraw(TurnContext $context, array $probability, string $label): array
    {
        $draw = $context->random->stream($label)->integer(0, $probability['denominator'] - 1);

        return ['draw' => $draw, 'success' => $draw < $probability['numerator']];
    }

    private function center(
        TurnContext $context,
        MapSpace $space,
        int $padding,
        string $label,
    ): GridCoordinate {
        $minimumX = $space->min_x - $padding;
        $maximumX = $space->max_x + $padding;
        $minimumY = $space->min_y - $padding;
        $maximumY = $space->max_y + $padding;
        if ($minimumX < DeterministicRandomStream::MINIMUM_INTEGER
            || $maximumX > DeterministicRandomStream::MAXIMUM_INTEGER
            || $minimumY < DeterministicRandomStream::MINIMUM_INTEGER
            || $maximumY > DeterministicRandomStream::MAXIMUM_INTEGER) {
            throw new DomainException('Disaster center draw bounds must fit signed 32-bit integers after World expansion.');
        }
        $stream = $context->random->stream($label);

        return new GridCoordinate(
            $stream->integer($minimumX, $maximumX),
            $stream->integer($minimumY, $maximumY),
        );
    }
}
