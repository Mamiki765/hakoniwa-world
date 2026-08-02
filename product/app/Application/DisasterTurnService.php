<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
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
    ) {}

    /** @return array{executed_disasters: int, damaged_cells: int} */
    public function executeGlobal(TurnContext $context): array
    {
        $rules = $this->rules($context);
        $metrics = ['executed_disasters' => 0, 'damaged_cells' => 0];
        if ($rules === null) {
            return $metrics;
        }
        $space = $this->surfaceSpace($context);

        $definitions = [
            'earthquake' => [TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_TRIGGER, TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER],
            'tsunami' => [TurnRandomStreamFactory::GLOBAL_TSUNAMI_TRIGGER, TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER],
            'typhoon' => [TurnRandomStreamFactory::GLOBAL_TYPHOON_TRIGGER, TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER],
            'meteor_shower' => [TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_TRIGGER, TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER],
            'huge_meteor' => [TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_TRIGGER, TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER],
            'eruption' => [TurnRandomStreamFactory::GLOBAL_ERUPTION_TRIGGER, TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER],
        ];

        foreach ($definitions as $key => [$triggerLabel, $centerLabel]) {
            $settings = $rules[$key];
            $trigger = $this->probabilityDraw($context, $settings['probability'], $triggerLabel);
            if (! $trigger['success']) {
                continue;
            }
            $center = $this->center($context, $space, $settings['center_padding'], $centerLabel);
            $this->events->record($context, 'disaster.triggered', $context->world, [
                'disaster_key' => $key,
                'center_x' => $center->x,
                'center_y' => $center->y,
                'draw' => $trigger['draw'],
                'numerator' => $settings['probability']['numerator'],
                'denominator' => $settings['probability']['denominator'],
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
                'huge_meteor' => $this->hugeMeteor($context, $space, $center, $settings),
                'eruption' => $this->eruption($context, $space, $center, $settings),
            };
        }

        return $metrics;
    }

    public function landLevelEarthquake(
        TurnContext $context,
        NationCommandQueueItem $item,
        int $x,
        int $y,
    ): bool {
        $settings = $context->ruleset->settings['turn_processing']['command_random_effects']['land_level_earthquake'] ?? null;
        if ($settings === null) {
            return false;
        }
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
        $rules = $this->rules($context);
        if ($rules === null) {
            return false;
        }
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
    private function hugeMeteor(TurnContext $context, MapSpace $space, GridCoordinate $center, array $settings): int
    {
        $damaged = 0;
        $coordinates = [...$center->ring(0), ...$center->ring(1), ...$center->ring(2)];
        foreach ($coordinates as $coordinate) {
            $cell = $this->cellAt($space, $coordinate);
            if ($cell === null || ! $this->isMutable($cell)) {
                continue;
            }
            $distance = $center->distanceTo($coordinate);
            if ($this->isCapital($cell)) {
                if ($distance === 0) {
                    $this->damageCapital($context, $cell, 'huge_meteor', 'deep_sea');
                } elseif ($distance === 1) {
                    $this->damageCapital($context, $cell, 'huge_meteor', 'excavation_or_shallow');
                } elseif ($this->hugeMeteorRingTwoTarget($cell, $settings)) {
                    $this->damageCapital($context, $cell, 'huge_meteor', 'facility_or_wasteland');
                } else {
                    continue;
                }
                $damaged++;

                continue;
            }
            if ($distance === 2) {
                if (! $this->hugeMeteorRingTwoTarget($cell, $settings)) {
                    continue;
                }
                $changed = $this->changeCell($context, $cell, 'huge_meteor', 'wasteland', false, 'disaster.cell_damaged');
            } elseif ($cell->terrain->key === 'sea' || $cell->terrain->key === 'shallow'
                || in_array($cell->facility?->key, $settings['seabed_facility_keys'], true)) {
                $changed = $this->changeCell($context, $cell, 'huge_meteor', 'sea', true, 'disaster.cell_damaged');
            } else {
                $changed = $this->changeCell(
                    $context,
                    $cell,
                    'huge_meteor',
                    $distance === 0 ? 'sea' : 'shallow',
                    true,
                    'disaster.cell_damaged',
                );
            }
            $damaged += $changed ? 1 : 0;
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
     */
    private function damageCapital(
        TurnContext $context,
        MapCell $cell,
        string $disasterKey,
        string $percentageKey,
        array $extra = [],
    ): void {
        $percentages = $context->ruleset->settings['capital_damage_percentages'] ?? null;
        $minimum = $context->ruleset->settings['capital_minimum_population'] ?? null;
        if (! is_array($percentages) || ! is_int($percentages[$percentageKey] ?? null)
            || ! is_int($minimum) || $minimum < 1) {
            throw new DomainException('The active ruleset has invalid Capital damage settings.');
        }
        $before = $cell->population;
        $percentage = $percentages[$percentageKey];
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
        if ($beforeTerrain === $terrainKey && $beforeFacility === null
            && $beforeOwner === $targetOwner && $beforePopulation === 0) {
            return false;
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

    /** @return array<string, array<string, mixed>>|null */
    private function rules(TurnContext $context): ?array
    {
        $turnProcessing = $context->ruleset->settings['turn_processing'] ?? null;
        if (! is_array($turnProcessing) || ! array_key_exists('disasters', $turnProcessing)) {
            return null;
        }
        $rules = $turnProcessing['disasters'];
        if (! is_array($rules)) {
            throw new DomainException('The active ruleset is missing disaster settings.');
        }

        return $rules;
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
        $stream = $context->random->stream($label);

        return new GridCoordinate(
            $stream->integer($space->min_x - $padding, $space->max_x + $padding),
            $stream->integer($space->min_y - $padding, $space->max_y + $padding),
        );
    }
}
