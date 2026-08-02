<?php

namespace App\Domain\Ruleset;

use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Economy\SalePolicy;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use DomainException;
use JsonException;

final class RulesetAuthoringValidator
{
    private const ARCHITECTURE_CHUNK_SIZE = 16;

    private const INITIAL_X_MIN = 0;

    private const INITIAL_X_MAX = 59;

    private const INITIAL_Y_MIN = 0;

    private const INITIAL_Y_MAX = 59;

    private const POSTGRESQL_INTEGER_MAX = 2_147_483_647;

    private const DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX = 2_147_483_648;

    private const PRODUCTION_DECIMAL_INTEGER_DIGITS = 12;

    private const PRODUCTION_DECIMAL_SCALE = 4;

    private const NUTRITION_DECIMAL_MAX_INTEGER = 99_999_999;

    private const POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS = 255;

    /** @var list<string> */
    private const FACILITY_SCALE_FIELDS = [
        'scale_unit_people',
        'initial_scale',
        'scale_increment',
        'maximum_scale',
        'workforce_per_scale_people',
    ];

    /** @var list<string> */
    private const REQUIRED_INITIAL_ISLAND_FACILITY_KEYS = ['village', 'missile_base', 'capital'];

    /** @var list<string> */
    private const TERRAIN_KEYS = ['sea', 'shallow', 'wasteland', 'plain', 'forest', 'mountain'];

    /** @var list<string> */
    private const REQUIRED_TOP_LEVEL_KEYS = [
        'key',
        'version',
        'chunk_size',
        'initial_x_min',
        'initial_x_max',
        'initial_y_min',
        'initial_y_max',
        'minimum_capital_distance',
        'capital_initial_population',
        'capital_minimum_population',
        'initial_money',
        'resource_definitions',
        'resource_sale_prices',
        'initial_resources',
        'default_sale_policy',
        'command_queue_limit',
        'terrain_quantities',
        'facility_definitions',
        'command_definitions',
        'production_definitions',
        'initial_territory_radius',
        'initial_island_land_radius',
        'initial_island_growth_radius',
        'initial_island_reservation_radius',
        'initial_island_growth_steps',
    ];

    /**
     * @param  array<string, mixed>  $settings
     * @return array{key: string, version: int, resources: int, facilities: int, commands: int, production: int}
     */
    public function validate(array $settings): array
    {
        $this->validateJsonAuthoredValue($settings, 'ruleset');
        try {
            json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new DomainException('ruleset must be JSON encodable.', previous: $exception);
        }

        $this->requireKeys($settings, self::REQUIRED_TOP_LEVEL_KEYS, 'ruleset');

        $key = $this->persistedString($settings['key'], 'ruleset.key');
        $version = $this->integer($settings['version'], 'ruleset.version', 1);
        if ($version > self::POSTGRESQL_INTEGER_MAX) {
            throw new DomainException(
                'ruleset.version must fit the PostgreSQL integer range 1..'
                .self::POSTGRESQL_INTEGER_MAX.'.',
            );
        }
        $chunkSize = $this->integer($settings['chunk_size'], 'ruleset.chunk_size', 1);
        if ($chunkSize !== self::ARCHITECTURE_CHUNK_SIZE) {
            throw new DomainException('ruleset.chunk_size must be exactly 16.');
        }
        $xMin = $this->integer($settings['initial_x_min'], 'ruleset.initial_x_min');
        $xMax = $this->integer($settings['initial_x_max'], 'ruleset.initial_x_max');
        $yMin = $this->integer($settings['initial_y_min'], 'ruleset.initial_y_min');
        $yMax = $this->integer($settings['initial_y_max'], 'ruleset.initial_y_max');
        if ($xMin !== self::INITIAL_X_MIN
            || $xMax !== self::INITIAL_X_MAX
            || $yMin !== self::INITIAL_Y_MIN
            || $yMax !== self::INITIAL_Y_MAX) {
            throw new DomainException('Ruleset initial bounds must be x=0..59 and y=0..59.');
        }

        $minimumCapitalDistance = $this->integer(
            $settings['minimum_capital_distance'],
            'ruleset.minimum_capital_distance',
            0,
        );
        $initialPopulation = $this->integer(
            $settings['capital_initial_population'],
            'ruleset.capital_initial_population',
            1,
        );
        $minimumPopulation = $this->integer(
            $settings['capital_minimum_population'],
            'ruleset.capital_minimum_population',
            1,
        );
        if ($minimumPopulation > $initialPopulation) {
            throw new DomainException('Ruleset minimum Capital population cannot exceed its initial population.');
        }

        $this->integer($settings['initial_money'], 'ruleset.initial_money', 0);
        $defaultSalePolicy = $this->string($settings['default_sale_policy'], 'ruleset.default_sale_policy');
        if (! SalePolicy::isSupportedRulesetDefault($defaultSalePolicy)) {
            throw new DomainException(
                'ruleset.default_sale_policy must be one of '
                .implode(', ', SalePolicy::rulesetDefaultValues()).'.',
            );
        }
        $commandQueueLimit = $this->integer(
            $settings['command_queue_limit'],
            'ruleset.command_queue_limit',
            1,
        );
        if ($commandQueueLimit > CommandQueueLimit::MAXIMUM) {
            throw new DomainException(
                'ruleset.command_queue_limit must be between 1 and '
                .CommandQueueLimit::MAXIMUM.'.',
            );
        }
        $territoryRadius = $this->integer(
            $settings['initial_territory_radius'],
            'ruleset.initial_territory_radius',
            0,
        );
        $landRadius = $this->integer(
            $settings['initial_island_land_radius'],
            'ruleset.initial_island_land_radius',
            0,
        );
        $growthRadius = $this->integer(
            $settings['initial_island_growth_radius'],
            'ruleset.initial_island_growth_radius',
            0,
        );
        $reservationRadius = $this->integer(
            $settings['initial_island_reservation_radius'],
            'ruleset.initial_island_reservation_radius',
            0,
        );
        $this->integer($settings['initial_island_growth_steps'], 'ruleset.initial_island_growth_steps', 0);
        if ($landRadius < 2) {
            throw new DomainException(
                'ruleset.initial_island_land_radius must be at least 2 to contain starter cells.',
            );
        }
        if ($landRadius < $territoryRadius) {
            throw new DomainException(
                'ruleset.initial_island_land_radius must be at least initial_territory_radius.',
            );
        }
        if ($reservationRadius < max($landRadius, $growthRadius, 2)) {
            throw new DomainException(
                'ruleset.initial_island_reservation_radius must be at least '
                .'max(initial_island_land_radius, initial_island_growth_radius, 2).',
            );
        }
        $maximumReservationRadius = min(
            intdiv($xMax - $xMin, 2),
            intdiv($yMax - $yMin, 2),
        );
        if ($reservationRadius > $maximumReservationRadius) {
            throw new DomainException(
                'ruleset.initial_island_reservation_radius must be at most '
                ."{$maximumReservationRadius} so the initial bounds contain a Capital candidate.",
            );
        }
        $maximumCapitalDistance = $this->maximumCapitalDistance(
            $xMin,
            $xMax,
            $yMin,
            $yMax,
            $reservationRadius,
        );
        if ($minimumCapitalDistance > $maximumCapitalDistance) {
            throw new DomainException(
                'ruleset.minimum_capital_distance must be at most '
                ."{$maximumCapitalDistance} for the initial bounds and reservation radius.",
            );
        }

        $resourceKeys = $this->validateResources($settings);
        $commandKeys = $this->definitionKeys(
            $this->list($settings['command_definitions'], 'ruleset.command_definitions'),
            'ruleset.command_definitions',
        );
        $productionKeys = $this->definitionKeys(
            $this->list($settings['production_definitions'], 'ruleset.production_definitions'),
            'ruleset.production_definitions',
        );
        $facilityKeys = $this->definitionKeys(
            $this->map($settings['facility_definitions'], 'ruleset.facility_definitions'),
            'ruleset.facility_definitions',
            true,
        );

        $this->validateInitialIslandFacilities($settings, $facilityKeys);
        $this->validateTerrainQuantities($settings);
        $this->validateFacilities($settings, $commandKeys, $productionKeys);
        $this->validateCommands($settings, $resourceKeys, $facilityKeys);
        $this->validateProduction($settings, $resourceKeys, $facilityKeys);
        $this->validateVersionAdditions(
            $settings,
            $resourceKeys,
            $facilityKeys,
            $reservationRadius,
            $landRadius,
        );

        return [
            'key' => $key,
            'version' => $version,
            'resources' => count($resourceKeys),
            'facilities' => count($facilityKeys),
            'commands' => count($commandKeys),
            'production' => count($productionKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function validateResources(array $settings): array
    {
        $definitions = $this->list($settings['resource_definitions'], 'ruleset.resource_definitions');
        $keys = $this->definitionKeys($definitions, 'ruleset.resource_definitions');
        $salePrices = $this->map($settings['resource_sale_prices'], 'ruleset.resource_sale_prices');

        foreach ($salePrices as $priceKey => $price) {
            $this->string($priceKey, 'ruleset.resource_sale_prices key');
            $this->integer($price, "ruleset.resource_sale_prices.{$priceKey}", 0);
        }

        foreach ($definitions as $index => $definition) {
            $path = "ruleset.resource_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'name', 'category', 'unit', 'nutrition_per_unit', 'storable', 'tradable',
                'sale_price_key', 'sort_order', 'metadata',
            ], $path);
            $this->persistedString($definition['key'], "{$path}.key");
            $this->persistedString($definition['name'], "{$path}.name");
            $this->persistedString($definition['category'], "{$path}.category");
            $this->persistedString($definition['unit'], "{$path}.unit");
            if ($definition['nutrition_per_unit'] !== null) {
                $nutrition = $this->integer(
                    $definition['nutrition_per_unit'],
                    "{$path}.nutrition_per_unit",
                    0,
                );
                if ($nutrition > self::NUTRITION_DECIMAL_MAX_INTEGER) {
                    throw new DomainException(
                        "{$path}.nutrition_per_unit must fit decimal(12,4) without rounding.",
                    );
                }
            }
            $this->boolean($definition['storable'], "{$path}.storable");
            $this->boolean($definition['tradable'], "{$path}.tradable");
            $priceKey = $this->persistedString($definition['sale_price_key'], "{$path}.sale_price_key");
            if (! array_key_exists($priceKey, $salePrices)) {
                throw new DomainException("{$path}.sale_price_key references missing price {$priceKey}.");
            }
            $this->persistedNonNegativeInteger($definition['sort_order'], "{$path}.sort_order");
            $this->map($definition['metadata'], "{$path}.metadata");
            if (array_key_exists('unit_label', $definition) && $definition['unit_label'] !== null) {
                $this->persistedString($definition['unit_label'], "{$path}.unit_label");
            }
        }

        $initial = $this->map($settings['initial_resources'], 'ruleset.initial_resources');
        foreach ($initial as $resourceKey => $amount) {
            $this->reference($resourceKey, $keys, 'ruleset.initial_resources');
            $this->integer($amount, "ruleset.initial_resources.{$resourceKey}", 0);
        }
        foreach ($keys as $resourceKey) {
            if (! array_key_exists($resourceKey, $initial)) {
                throw new DomainException("ruleset.initial_resources is missing {$resourceKey}.");
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $settings */
    private function validateTerrainQuantities(array $settings): void
    {
        $quantities = $this->map($settings['terrain_quantities'], 'ruleset.terrain_quantities');
        if (! array_key_exists('forest', $quantities)) {
            throw new DomainException('ruleset.terrain_quantities must include forest.');
        }
        foreach ($quantities as $terrainKey => $quantity) {
            $this->reference($terrainKey, self::TERRAIN_KEYS, 'ruleset.terrain_quantities');
            $path = "ruleset.terrain_quantities.{$terrainKey}";
            $quantity = $this->map($quantity, $path);
            $this->requireKeys($quantity, [
                'key', 'label', 'unit', 'initial_quantity', 'minimum_quantity',
                'maximum_quantity', 'growth_increment', 'growth_rule_key',
            ], $path);
            $this->persistedString($quantity['key'], "{$path}.key");
            $this->persistedString($quantity['label'], "{$path}.label");
            $this->persistedString($quantity['unit'], "{$path}.unit");
            $initial = $this->integer($quantity['initial_quantity'], "{$path}.initial_quantity", 0);
            $minimum = $this->integer($quantity['minimum_quantity'], "{$path}.minimum_quantity", 0);
            $maximum = $this->integer($quantity['maximum_quantity'], "{$path}.maximum_quantity", 0);
            $this->integer($quantity['growth_increment'], "{$path}.growth_increment", 0);
            $this->persistedString($quantity['growth_rule_key'], "{$path}.growth_rule_key");
            if ($minimum > $initial || $initial > $maximum) {
                throw new DomainException("{$path} requires minimum <= initial <= maximum.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $facilityKeys
     */
    private function validateInitialIslandFacilities(array $settings, array $facilityKeys): void
    {
        foreach (self::REQUIRED_INITIAL_ISLAND_FACILITY_KEYS as $facilityKey) {
            if (! in_array($facilityKey, $facilityKeys, true)) {
                throw new DomainException(
                    "ruleset.facility_definitions must include {$facilityKey} for initial island generation.",
                );
            }
        }

        $path = 'ruleset.facility_definitions.missile_base';
        $missileBase = $this->map($settings['facility_definitions']['missile_base'], $path);
        $this->requireKeys(
            $missileBase,
            ['visibility_policy', 'initial_experience', 'maximum_experience'],
            $path,
        );
        $visibilityPolicy = $this->persistedString(
            $missileBase['visibility_policy'],
            "{$path}.visibility_policy",
        );
        if ($visibilityPolicy !== FacilityVisibilityPolicy::Disguised->value) {
            throw new DomainException("{$path}.visibility_policy must be disguised.");
        }
        $initialExperience = $this->persistedNonNegativeInteger(
            $missileBase['initial_experience'],
            "{$path}.initial_experience",
        );
        $maximumExperience = $this->persistedNonNegativeInteger(
            $missileBase['maximum_experience'],
            "{$path}.maximum_experience",
        );
        if ($initialExperience > $maximumExperience) {
            throw new DomainException(
                "{$path}.initial_experience cannot exceed maximum_experience.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $commandKeys
     * @param  list<string>  $productionKeys
     */
    private function validateFacilities(array $settings, array $commandKeys, array $productionKeys): void
    {
        $assetKeys = [];
        foreach ($this->map($settings['facility_definitions'], 'ruleset.facility_definitions') as $key => $definition) {
            $path = "ruleset.facility_definitions.{$key}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'name', 'asset_key', 'visibility_policy', 'build_command_key', 'scale_unit_people',
                'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people',
                'production_definition_key', 'buildable_terrain_keys',
            ], $path);
            $this->persistedString($key, "{$path} key");
            $this->persistedString($definition['name'], "{$path}.name");
            $assetKey = $this->persistedString($definition['asset_key'], "{$path}.asset_key");
            if (in_array($assetKey, $assetKeys, true)) {
                throw new DomainException(
                    "{$path}.asset_key duplicates facility asset key {$assetKey}.",
                );
            }
            $assetKeys[] = $assetKey;
            $visibilityPolicy = $this->persistedString(
                $definition['visibility_policy'],
                "{$path}.visibility_policy",
            );
            if (! FacilityVisibilityPolicy::isSupported($visibilityPolicy)) {
                throw new DomainException(
                    "{$path}.visibility_policy must be one of "
                    .implode(', ', FacilityVisibilityPolicy::values()).'.',
                );
            }
            $this->nullableReference($definition['build_command_key'], $commandKeys, "{$path}.build_command_key");
            $this->nullableReference(
                $definition['production_definition_key'],
                $productionKeys,
                "{$path}.production_definition_key",
            );
            $this->validateFacilityScaleTuple($definition, $path);
            foreach ([
                'initial_experience', 'maximum_experience',
            ] as $field) {
                if (array_key_exists($field, $definition) && $definition[$field] !== null) {
                    $this->integer($definition[$field], "{$path}.{$field}", 0);
                }
            }
            foreach ($this->list($definition['buildable_terrain_keys'], "{$path}.buildable_terrain_keys") as $terrainKey) {
                $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.buildable_terrain_keys");
            }
            if (array_key_exists('disguise_terrain_key', $definition)) {
                $this->nullableReference(
                    $definition['disguise_terrain_key'],
                    self::TERRAIN_KEYS,
                    "{$path}.disguise_terrain_key",
                );
            }
            if (array_key_exists('disguise_asset_key', $definition) && $definition['disguise_asset_key'] !== null) {
                $this->persistedString($definition['disguise_asset_key'], "{$path}.disguise_asset_key");
            }
            if (array_key_exists('level_thresholds', $definition)) {
                foreach ($this->list($definition['level_thresholds'], "{$path}.level_thresholds") as $index => $value) {
                    $this->integer($value, "{$path}.level_thresholds.{$index}", 0);
                }
            }
            if (array_key_exists('launch_capacity_by_level', $definition)) {
                foreach ($this->map($definition['launch_capacity_by_level'], "{$path}.launch_capacity_by_level") as $level => $capacity) {
                    if (! is_int($level) || $level < 1) {
                        throw new DomainException("{$path}.launch_capacity_by_level keys must be positive integers.");
                    }
                    $this->integer($capacity, "{$path}.launch_capacity_by_level.{$level}", 0);
                }
            }
        }
    }

    private function maximumCapitalDistance(
        int $xMin,
        int $xMax,
        int $yMin,
        int $yMax,
        int $reservationRadius,
    ): int {
        $candidateXMin = $xMin + $reservationRadius;
        $candidateXMax = $xMax - $reservationRadius;
        $candidateYMin = $yMin + $reservationRadius;
        $candidateYMax = $yMax - $reservationRadius;
        $corners = [
            new GridCoordinate($candidateXMin, $candidateYMin),
            new GridCoordinate($candidateXMin, $candidateYMax),
            new GridCoordinate($candidateXMax, $candidateYMin),
            new GridCoordinate($candidateXMax, $candidateYMax),
        ];
        $maximumDistance = 0;

        foreach ($corners as $index => $corner) {
            foreach (array_slice($corners, $index + 1) as $otherCorner) {
                $maximumDistance = max($maximumDistance, $corner->distanceTo($otherCorner));
            }
        }

        return $maximumDistance;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateCommands(array $settings, array $resourceKeys, array $facilityKeys): void
    {
        foreach ($this->list($settings['command_definitions'], 'ruleset.command_definitions') as $index => $definition) {
            $path = "ruleset.command_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'name', 'description', 'target_type', 'target_terrain_keys',
                'target_facility_keys', 'requires_empty_facility', 'cost_money', 'required_resources',
                'execution_phase', 'result_terrain_key', 'result_facility_key', 'sort_order', 'metadata',
            ], $path);
            $commandKey = $this->persistedString($definition['key'], "{$path}.key");
            $this->persistedString($definition['name'], "{$path}.name");
            $this->string($definition['description'], "{$path}.description");
            $this->persistedString($definition['target_type'], "{$path}.target_type");
            $this->persistedString($definition['execution_phase'], "{$path}.execution_phase");
            foreach ($this->list($definition['target_terrain_keys'], "{$path}.target_terrain_keys") as $terrainKey) {
                $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.target_terrain_keys");
            }
            foreach ($this->list($definition['target_facility_keys'], "{$path}.target_facility_keys") as $facilityKey) {
                $this->reference($facilityKey, $facilityKeys, "{$path}.target_facility_keys");
            }
            $this->nullableReference($definition['result_terrain_key'], self::TERRAIN_KEYS, "{$path}.result_terrain_key");
            $this->nullableReference($definition['result_facility_key'], $facilityKeys, "{$path}.result_facility_key");
            $this->boolean($definition['requires_empty_facility'], "{$path}.requires_empty_facility");
            $this->integer($definition['cost_money'], "{$path}.cost_money", 0);
            foreach ($this->map($definition['required_resources'], "{$path}.required_resources") as $resourceKey => $amount) {
                $this->reference($resourceKey, $resourceKeys, "{$path}.required_resources");
                $this->integer($amount, "{$path}.required_resources.{$resourceKey}", 0);
            }
            $this->persistedNonNegativeInteger($definition['sort_order'], "{$path}.sort_order");
            $metadata = $this->map($definition['metadata'], "{$path}.metadata");
            if ($commandKey === 'reclaim' && array_key_exists('adjacent_water_spread_maximum', $metadata)) {
                $this->integer(
                    $metadata['adjacent_water_spread_maximum'],
                    "{$path}.metadata.adjacent_water_spread_maximum",
                    0,
                );
            }
            if ($commandKey === 'excavate' && array_key_exists('oil_search_effect_key', $metadata)) {
                $this->persistedString(
                    $metadata['oil_search_effect_key'],
                    "{$path}.metadata.oil_search_effect_key",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateProduction(array $settings, array $resourceKeys, array $facilityKeys): void
    {
        $salePrices = $this->map($settings['resource_sale_prices'], 'ruleset.resource_sale_prices');
        $productionFacilityKeys = [];
        foreach ($this->list($settings['production_definitions'], 'ruleset.production_definitions') as $index => $definition) {
            $path = "ruleset.production_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'facility_key', 'output_resource_key', 'production_per_scale',
                'required_workforce_per_scale', 'operating_condition', 'price_reference', 'metadata',
            ], $path);
            $this->persistedString($definition['key'], "{$path}.key");
            $facilityKey = $this->reference(
                $definition['facility_key'],
                $facilityKeys,
                "{$path}.facility_key",
            );
            if (in_array($facilityKey, $productionFacilityKeys, true)) {
                throw new DomainException(
                    "{$path}.facility_key duplicates production facility {$facilityKey}.",
                );
            }
            $productionFacilityKeys[] = $facilityKey;
            $this->reference($definition['output_resource_key'], $resourceKeys, "{$path}.output_resource_key");
            $this->productionDecimal($definition['production_per_scale'], "{$path}.production_per_scale");
            $this->persistedNonNegativeInteger(
                $definition['required_workforce_per_scale'],
                "{$path}.required_workforce_per_scale",
            );
            $this->persistedString($definition['operating_condition'], "{$path}.operating_condition");
            $priceReference = $this->persistedString(
                $definition['price_reference'],
                "{$path}.price_reference",
            );
            if (! array_key_exists($priceReference, $salePrices)) {
                throw new DomainException("{$path}.price_reference references missing price {$priceReference}.");
            }
            $this->map($definition['metadata'], "{$path}.metadata");
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateVersionAdditions(
        array $settings,
        array $resourceKeys,
        array $facilityKeys,
        int $reservationRadius,
        int $landRadius,
    ): void {
        if (array_key_exists('development_plan_quantity', $settings)
            && ! DevelopmentPlanQuantity::matchesContract($settings['development_plan_quantity'])) {
            throw new DomainException('ruleset.development_plan_quantity does not match the canonical quantity contract.');
        }
        if (array_key_exists('initial_island_minimum_shallow_cells', $settings)) {
            $minimumShallowCells = $this->integer(
                $settings['initial_island_minimum_shallow_cells'],
                'ruleset.initial_island_minimum_shallow_cells',
                0,
            );
            $reservationCellCount = 1 + (3 * $reservationRadius * ($reservationRadius + 1));
            $permanentLandCellCount = 1 + (3 * $landRadius * ($landRadius + 1));
            $reservationWaterCellCapacity = $reservationCellCount - $permanentLandCellCount;
            $coastalCandidateCapacity = $reservationRadius > $landRadius
                ? 6 * ($landRadius + 1)
                : 0;
            $maximumShallowCells = min($reservationWaterCellCapacity, $coastalCandidateCapacity);
            if ($minimumShallowCells > $maximumShallowCells) {
                throw new DomainException(
                    'ruleset.initial_island_minimum_shallow_cells cannot exceed '
                    ."the guaranteed coastal candidate capacity {$maximumShallowCells}.",
                );
            }
        }
        if (array_key_exists('base_money_capacity', $settings)) {
            $moneyCapacity = $this->integer(
                $settings['base_money_capacity'],
                'ruleset.base_money_capacity',
                0,
            );
            $initialMoney = $this->integer($settings['initial_money'], 'ruleset.initial_money', 0);
            if ($initialMoney > $moneyCapacity) {
                throw new DomainException(
                    'ruleset.initial_money cannot exceed ruleset.base_money_capacity.',
                );
            }
        }
        if (array_key_exists('base_food_capacity_tons', $settings)) {
            $foodCapacity = $this->integer(
                $settings['base_food_capacity_tons'],
                'ruleset.base_food_capacity_tons',
                0,
            );
            $initialResources = $this->map($settings['initial_resources'], 'ruleset.initial_resources');
            foreach ($this->list($settings['resource_definitions'], 'ruleset.resource_definitions') as $definition) {
                $definition = $this->map($definition, 'ruleset.resource_definitions entry');
                if ($definition['category'] !== 'food') {
                    continue;
                }
                $resourceKey = $this->string(
                    $definition['key'],
                    'ruleset.resource_definitions entry.key',
                );
                $amount = $this->integer(
                    $initialResources[$resourceKey],
                    "ruleset.initial_resources.{$resourceKey}",
                    0,
                );
                if ($amount > $foodCapacity) {
                    throw new DomainException(
                        'ruleset initial food total cannot exceed ruleset.base_food_capacity_tons.',
                    );
                }
                $foodCapacity -= $amount;
            }
        }
        if (array_key_exists('inventory_sale_rates', $settings)) {
            foreach ($this->map($settings['inventory_sale_rates'], 'ruleset.inventory_sale_rates') as $resourceKey => $rate) {
                $this->reference($resourceKey, $resourceKeys, 'ruleset.inventory_sale_rates');
                $path = "ruleset.inventory_sale_rates.{$resourceKey}";
                $rate = $this->map($rate, $path);
                $this->requireKeys($rate, ['inventory_units', 'money_units'], $path);
                $this->integer($rate['inventory_units'], "{$path}.inventory_units", 1);
                $this->integer($rate['money_units'], "{$path}.money_units", 1);
            }
        }
        if (array_key_exists('turn_processing', $settings)) {
            $this->validateTurnProcessing($settings['turn_processing'], $resourceKeys, $facilityKeys, $settings);
        }
    }

    /**
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     * @param  array<string, mixed>  $settings
     */
    private function validateTurnProcessing(
        mixed $authored,
        array $resourceKeys,
        array $facilityKeys,
        array $settings,
    ): void {
        $path = 'ruleset.turn_processing';
        $turn = $this->map($authored, $path);
        $this->requireKeys($turn, [
            'automatic_finance_money', 'food', 'workforce', 'settlement', 'famine', 'riot',
            'command_random_effects', 'sale_policy',
        ], $path);
        $this->integer($turn['automatic_finance_money'], "{$path}.automatic_finance_money", 0);

        $food = $this->map($turn['food'], "{$path}.food");
        $this->requireKeys($food, ['population_per_nutrition', 'consumption_priority'], "{$path}.food");
        $this->integer($food['population_per_nutrition'], "{$path}.food.population_per_nutrition", 1);
        $priority = $this->list($food['consumption_priority'], "{$path}.food.consumption_priority");
        if ($priority !== ['wheat', 'fish', 'monster_meat']) {
            throw new DomainException("{$path}.food.consumption_priority must be wheat, fish, monster_meat.");
        }
        foreach ($priority as $resourceKey) {
            $this->reference($resourceKey, $resourceKeys, "{$path}.food.consumption_priority");
        }
        $resourceDefinitions = [];
        foreach ($this->list($settings['resource_definitions'], 'ruleset.resource_definitions') as $definitionValue) {
            $definition = $this->map($definitionValue, 'ruleset.resource_definitions entry');
            $resourceDefinitions[$this->string($definition['key'], 'ruleset.resource_definitions entry.key')] = $definition;
        }
        foreach ($priority as $resourceKey) {
            $definition = $resourceDefinitions[$resourceKey];
            if ($definition['category'] !== 'food' || $definition['unit'] !== 'ton') {
                throw new DomainException("{$path}.food resource {$resourceKey} must use category food and canonical ton units.");
            }
            $this->integer(
                $definition['nutrition_per_unit'],
                "ruleset.resource_definitions.{$resourceKey}.nutrition_per_unit",
                1,
            );
        }

        $workforce = $this->map($turn['workforce'], "{$path}.workforce");
        $this->requireKeys($workforce, [
            'priority', 'farm_output_per_worker', 'factory_output_per_worker',
            'mine_output_per_worker', 'allocation_rule',
        ], "{$path}.workforce");
        if ($this->list($workforce['priority'], "{$path}.workforce.priority") !== ['farm', 'factory_mine']) {
            throw new DomainException("{$path}.workforce.priority must be farm, factory_mine.");
        }
        foreach (['farm_output_per_worker', 'factory_output_per_worker', 'mine_output_per_worker'] as $key) {
            $this->integer($workforce[$key], "{$path}.workforce.{$key}", 1);
        }
        if ($this->persistedString($workforce['allocation_rule'], "{$path}.workforce.allocation_rule") !== 'capacity_proportional_largest_remainder') {
            throw new DomainException("{$path}.workforce.allocation_rule must use deterministic largest remainder allocation.");
        }
        $productionByFacility = [];
        foreach ($this->list($settings['production_definitions'], 'ruleset.production_definitions') as $definitionValue) {
            $definition = $this->map($definitionValue, 'ruleset.production_definitions entry');
            $productionByFacility[$this->string($definition['facility_key'], 'ruleset.production_definitions entry.facility_key')] = $definition;
        }
        foreach (['farm' => 'wheat', 'factory' => 'industrial_goods', 'mine' => 'minerals'] as $facilityKey => $resourceKey) {
            $definition = $productionByFacility[$facilityKey] ?? null;
            if (! is_array($definition)) {
                throw new DomainException("{$path}.workforce requires a production definition for {$facilityKey}.");
            }
            $workersPerScale = $this->integer(
                $definition['required_workforce_per_scale'],
                "ruleset.production_definitions.{$facilityKey}.required_workforce_per_scale",
                1,
            );
            $outputPerWorker = $this->integer(
                $workforce[$facilityKey === 'farm' ? 'farm_output_per_worker' : $facilityKey.'_output_per_worker'],
                "{$path}.workforce.{$facilityKey}_output_per_worker",
                1,
            );
            $productionPerScale = $this->integer(
                $definition['production_per_scale'],
                "ruleset.production_definitions.{$facilityKey}.production_per_scale",
                1,
            );
            if ($productionPerScale !== $workersPerScale * $outputPerWorker) {
                throw new DomainException("{$path}.workforce {$facilityKey} output must match production per scale.");
            }
            if ($definition['output_resource_key'] !== $resourceKey
                || $definition['operating_condition'] !== 'turn_start_workforce_allocation') {
                throw new DomainException("{$path}.workforce {$facilityKey} production mapping is incompatible.");
            }
            $facilityDefinition = $this->map(
                $settings['facility_definitions'][$facilityKey],
                "ruleset.facility_definitions.{$facilityKey}",
            );
            if ($workersPerScale !== $facilityDefinition['scale_unit_people']
                || $workersPerScale !== $facilityDefinition['workforce_per_scale_people']) {
                throw new DomainException("{$path}.workforce {$facilityKey} scale and workforce units must match.");
            }
        }

        $settlement = $this->map($turn['settlement'], "{$path}.settlement");
        $this->requireKeys($settlement, [
            'appearance_probability', 'initial_population', 'eligible_terrain_key',
            'adjacent_facility_key', 'stages', 'sea_edge_bands', 'ordinary_growth',
            'attraction_growth', 'attraction_maximum_population',
        ], "{$path}.settlement");
        $this->probability($settlement['appearance_probability'], "{$path}.settlement.appearance_probability");
        $this->integer($settlement['initial_population'], "{$path}.settlement.initial_population", 1);
        $this->reference($settlement['eligible_terrain_key'], self::TERRAIN_KEYS, "{$path}.settlement.eligible_terrain_key");
        $this->reference($settlement['adjacent_facility_key'], $facilityKeys, "{$path}.settlement.adjacent_facility_key");
        $stages = $this->map($settlement['stages'], "{$path}.settlement.stages");
        $this->requireKeys($stages, ['village', 'town', 'city'], "{$path}.settlement.stages");
        $facilityDefinitions = $this->map($settings['facility_definitions'], 'ruleset.facility_definitions');
        $previousMaximum = 0;
        foreach (['village', 'town', 'city'] as $stageKey) {
            $stage = $this->map($stages[$stageKey], "{$path}.settlement.stages.{$stageKey}");
            $this->requireKeys($stage, ['facility_key', 'minimum_population', 'maximum_population'], "{$path}.settlement.stages.{$stageKey}");
            $stageFacilityKey = $this->reference($stage['facility_key'], $facilityKeys, "{$path}.settlement.stages.{$stageKey}.facility_key");
            $stageFacility = $this->map(
                $facilityDefinitions[$stageFacilityKey],
                "ruleset.facility_definitions.{$stageFacilityKey}",
            );
            if ($stageFacility['build_command_key'] !== null || $stageFacility['scale_unit_people'] !== null) {
                throw new DomainException("{$path}.settlement stage facility {$stageFacilityKey} must be population-derived.");
            }
            $minimum = $this->integer($stage['minimum_population'], "{$path}.settlement.stages.{$stageKey}.minimum_population", 1);
            $maximum = $this->integer($stage['maximum_population'], "{$path}.settlement.stages.{$stageKey}.maximum_population", $minimum);
            if ($minimum !== $previousMaximum + 1) {
                throw new DomainException("{$path}.settlement.stages must use contiguous population thresholds.");
            }
            $previousMaximum = $maximum;
        }
        $village = $this->map($stages['village'], "{$path}.settlement.stages.village");
        $initialSettlementPopulation = $this->integer(
            $settlement['initial_population'],
            "{$path}.settlement.initial_population",
            1,
        );
        if ($initialSettlementPopulation < $village['minimum_population']
            || $initialSettlementPopulation > $village['maximum_population']) {
            throw new DomainException("{$path}.settlement initial population must be in the village stage.");
        }
        $previousMinimumSeaCells = null;
        $lastMinimumSeaCells = null;
        $largestOrdinaryMaximum = 0;
        foreach ($this->list($settlement['sea_edge_bands'], "{$path}.settlement.sea_edge_bands") as $index => $bandValue) {
            $band = $this->map($bandValue, "{$path}.settlement.sea_edge_bands.{$index}");
            $this->requireKeys($band, ['minimum_sea_cells', 'maximum_population', 'growth_multiplier'], "{$path}.settlement.sea_edge_bands.{$index}");
            $minimumSeaCells = $this->integer($band['minimum_sea_cells'], "{$path}.settlement.sea_edge_bands.{$index}.minimum_sea_cells", 0);
            if ($previousMinimumSeaCells !== null && $minimumSeaCells >= $previousMinimumSeaCells) {
                throw new DomainException("{$path}.settlement.sea_edge_bands must use descending minimums.");
            }
            $previousMinimumSeaCells = $minimumSeaCells;
            $lastMinimumSeaCells = $minimumSeaCells;
            $maximumPopulation = $this->integer($band['maximum_population'], "{$path}.settlement.sea_edge_bands.{$index}.maximum_population", 1);
            $largestOrdinaryMaximum = max($largestOrdinaryMaximum, $maximumPopulation);
            $this->integer($band['growth_multiplier'], "{$path}.settlement.sea_edge_bands.{$index}.growth_multiplier", 1);
        }
        if ($lastMinimumSeaCells !== 0) {
            throw new DomainException("{$path}.settlement.sea_edge_bands must end at minimum zero.");
        }
        foreach (['ordinary_growth', 'attraction_growth'] as $growthKey) {
            $growth = $this->map($settlement[$growthKey], "{$path}.settlement.{$growthKey}");
            $this->requireKeys($growth, ['minimum', 'maximum', 'unit_people'], "{$path}.settlement.{$growthKey}");
            $minimum = $this->integer($growth['minimum'], "{$path}.settlement.{$growthKey}.minimum", 0);
            $maximum = $this->integer($growth['maximum'], "{$path}.settlement.{$growthKey}.maximum", $minimum);
            $this->integer($growth['unit_people'], "{$path}.settlement.{$growthKey}.unit_people", 1);
        }
        $attractionMaximum = $this->integer($settlement['attraction_maximum_population'], "{$path}.settlement.attraction_maximum_population", 1);
        if ($attractionMaximum < $largestOrdinaryMaximum) {
            throw new DomainException("{$path}.settlement attraction maximum cannot be below an ordinary maximum.");
        }

        $famine = $this->map($turn['famine'], "{$path}.famine");
        $this->requireKeys($famine, ['loss_minimum', 'loss_maximum', 'loss_unit_people'], "{$path}.famine");
        $lossMinimum = $this->integer($famine['loss_minimum'], "{$path}.famine.loss_minimum", 0);
        $this->integer($famine['loss_maximum'], "{$path}.famine.loss_maximum", $lossMinimum);
        $this->integer($famine['loss_unit_people'], "{$path}.famine.loss_unit_people", 1);

        $riot = $this->map($turn['riot'], "{$path}.riot");
        $this->requireKeys($riot, ['probability', 'facility_keys'], "{$path}.riot");
        $this->probability($riot['probability'], "{$path}.riot.probability");
        foreach ($this->list($riot['facility_keys'], "{$path}.riot.facility_keys") as $facilityKey) {
            $this->reference($facilityKey, $facilityKeys, "{$path}.riot.facility_keys");
        }

        $effects = $this->map($turn['command_random_effects'], "{$path}.command_random_effects");
        $this->requireKeys($effects, ['land_clear_buried_treasure'], "{$path}.command_random_effects");
        $treasure = $this->map($effects['land_clear_buried_treasure'], "{$path}.command_random_effects.land_clear_buried_treasure");
        $this->requireKeys($treasure, ['probability', 'reward_minimum_money', 'reward_maximum_money'], "{$path}.command_random_effects.land_clear_buried_treasure");
        $this->probability($treasure['probability'], "{$path}.command_random_effects.land_clear_buried_treasure.probability");
        $rewardMinimum = $this->integer($treasure['reward_minimum_money'], "{$path}.command_random_effects.land_clear_buried_treasure.reward_minimum_money", 0);
        $this->integer($treasure['reward_maximum_money'], "{$path}.command_random_effects.land_clear_buried_treasure.reward_maximum_money", $rewardMinimum);

        foreach ($this->list($settings['command_definitions'], 'ruleset.command_definitions') as $index => $definitionValue) {
            $commandPath = "ruleset.command_definitions.{$index}";
            $definition = $this->map($definitionValue, $commandPath);
            if (($definition['key'] ?? null) !== 'excavate') {
                continue;
            }
            $metadata = $this->map($definition['metadata'] ?? null, "{$commandPath}.metadata");
            if (! array_key_exists('oil_search_effect_key', $metadata)) {
                break;
            }
            $effectKey = $this->persistedString(
                $metadata['oil_search_effect_key'],
                "{$commandPath}.metadata.oil_search_effect_key",
            );
            if (! array_key_exists($effectKey, $effects)) {
                throw new DomainException(
                    "{$commandPath}.metadata.oil_search_effect_key references missing command random effect {$effectKey}.",
                );
            }
            if ($effectKey !== 'seabed_oil_search') {
                throw new DomainException(
                    "{$commandPath}.metadata.oil_search_effect_key must reference the validated seabed_oil_search effect.",
                );
            }
            $this->integer($definition['cost_money'] ?? null, "{$commandPath}.cost_money", 1);
            break;
        }

        if (array_key_exists('seabed_oil_search', $effects)) {
            $oilPath = "{$path}.command_random_effects.seabed_oil_search";
            $oil = $this->map($effects['seabed_oil_search'], $oilPath);
            $this->requireKeys(
                $oil,
                ['facility_key', 'draw_denominator', 'success_threshold_per_cost_unit'],
                $oilPath,
            );
            $facilityKey = $this->reference($oil['facility_key'], $facilityKeys, "{$oilPath}.facility_key");
            $facility = $this->map(
                $settings['facility_definitions'][$facilityKey],
                "ruleset.facility_definitions.{$facilityKey}",
            );
            $buildableTerrainKeys = $this->list(
                $facility['buildable_terrain_keys'] ?? null,
                "ruleset.facility_definitions.{$facilityKey}.buildable_terrain_keys",
            );
            if (! in_array('sea', $buildableTerrainKeys, true)) {
                throw new DomainException(
                    "{$oilPath}.facility_key must reference a facility buildable on sea terrain.",
                );
            }
            $denominator = $this->integer($oil['draw_denominator'], "{$oilPath}.draw_denominator", 1);
            if ($denominator > self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX) {
                throw new DomainException(
                    "{$oilPath}.draw_denominator must be at most ".self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX.'.',
                );
            }
            $thresholdPerUnit = $this->integer(
                $oil['success_threshold_per_cost_unit'],
                "{$oilPath}.success_threshold_per_cost_unit",
                1,
            );
            $quantity = $this->map($settings['development_plan_quantity'], 'ruleset.development_plan_quantity');
            $maximumQuantity = $this->integer(
                $quantity['maximum'] ?? null,
                'ruleset.development_plan_quantity.maximum',
                1,
            );
            if ($maximumQuantity * $thresholdPerUnit > $denominator) {
                throw new DomainException(
                    "{$oilPath} maximum quantity threshold cannot exceed draw_denominator.",
                );
            }
        }

        $salePolicy = $this->map($turn['sale_policy'], "{$path}.sale_policy");
        $this->requireKeys($salePolicy, ['sell_all_forbidden_resource_keys'], "{$path}.sale_policy");
        foreach ($this->list($salePolicy['sell_all_forbidden_resource_keys'], "{$path}.sale_policy.sell_all_forbidden_resource_keys") as $resourceKey) {
            $this->reference($resourceKey, $resourceKeys, "{$path}.sale_policy.sell_all_forbidden_resource_keys");
        }
        if (! in_array('wheat', $salePolicy['sell_all_forbidden_resource_keys'], true)) {
            throw new DomainException("{$path}.sale_policy must forbid sell_all for wheat.");
        }
        if ($settings['default_sale_policy'] !== 'stockpile') {
            throw new DomainException("{$path}.sale_policy requires stockpile as the wheat-safe default.");
        }

        $rates = $this->map($settings['inventory_sale_rates'] ?? null, 'ruleset.inventory_sale_rates');
        foreach ($resourceDefinitions as $resourceKey => $definition) {
            if ($definition['tradable'] === true && ! array_key_exists($resourceKey, $rates)) {
                throw new DomainException("ruleset.inventory_sale_rates is missing tradable resource {$resourceKey}.");
            }
        }
    }

    private function probability(mixed $authored, string $path): void
    {
        $probability = $this->map($authored, $path);
        $this->requireKeys($probability, ['numerator', 'denominator'], $path);
        $numerator = $this->integer($probability['numerator'], "{$path}.numerator", 0);
        $denominator = $this->integer($probability['denominator'], "{$path}.denominator", 1);
        if ($numerator > $denominator) {
            throw new DomainException("{$path}.numerator cannot exceed denominator.");
        }
    }

    /**
     * @param  list<array<string, mixed>>|array<string, array<string, mixed>>  $definitions
     * @return list<string>
     */
    private function definitionKeys(array $definitions, string $path, bool $associative = false): array
    {
        $keys = [];
        foreach ($definitions as $index => $definition) {
            if ($associative) {
                $key = $this->persistedString($index, "{$path} key");
                $this->map($definition, "{$path}.{$key}");
            } else {
                $definition = $this->map($definition, "{$path}.{$index}");
                if (! array_key_exists('key', $definition)) {
                    throw new DomainException("{$path}.{$index} is missing required key.");
                }
                $key = $this->persistedString($definition['key'], "{$path}.{$index}.key");
            }
            if (in_array($key, $keys, true)) {
                throw new DomainException("{$path} contains duplicate definition key {$key}.");
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** @param list<string> $allowed */
    private function reference(mixed $value, array $allowed, string $path): string
    {
        $reference = $this->string($value, $path);
        if (! in_array($reference, $allowed, true)) {
            throw new DomainException("{$path} references missing catalog or definition {$reference}.");
        }

        return $reference;
    }

    /** @param list<string> $allowed */
    private function nullableReference(mixed $value, array $allowed, string $path): ?string
    {
        return $value === null ? null : $this->reference($value, $allowed, $path);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $required
     */
    private function requireKeys(array $values, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $values)) {
                throw new DomainException("{$path} is missing required key {$key}.");
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function validateFacilityScaleTuple(array $definition, string $path): void
    {
        $nonNullFields = array_values(array_filter(
            self::FACILITY_SCALE_FIELDS,
            static fn (string $field): bool => $definition[$field] !== null,
        ));
        if ($nonNullFields === []) {
            return;
        }
        if (count($nonNullFields) !== count(self::FACILITY_SCALE_FIELDS)) {
            throw new DomainException(
                "{$path} scale fields must either all be null or all be non-null.",
            );
        }

        /** @var array<string, int> $values */
        $values = [];
        foreach (self::FACILITY_SCALE_FIELDS as $field) {
            $values[$field] = $this->persistedNonNegativeInteger(
                $definition[$field],
                "{$path}.{$field}",
            );
        }
        if ($values['initial_scale'] > $values['maximum_scale']) {
            throw new DomainException(
                "{$path}.initial_scale cannot exceed maximum_scale.",
            );
        }
    }

    private function persistedNonNegativeInteger(mixed $value, string $path): int
    {
        $value = $this->integer($value, $path, 0);
        if ($value > self::POSTGRESQL_INTEGER_MAX) {
            throw new DomainException(
                "{$path} must fit the PostgreSQL integer range 0..".self::POSTGRESQL_INTEGER_MAX.'.',
            );
        }

        return $value;
    }

    private function productionDecimal(mixed $value, string $path): int|float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new DomainException(
                "{$path} must fit decimal(16,4) without rounding.",
            );
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new DomainException(
                "{$path} must be finite and fit decimal(16,4) without rounding.",
            );
        }

        $serialized = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (str_starts_with($serialized, '-')) {
            throw new DomainException(
                "{$path} must be non-negative with at most "
                .self::PRODUCTION_DECIMAL_INTEGER_DIGITS.' integer digits.',
            );
        }
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $serialized, $parts)) {
            throw new DomainException(
                "{$path} must fit decimal(16,4) without rounding.",
            );
        }

        $integer = $parts[1];
        $fraction = $parts[2] ?? '';
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + (int) ($parts[3] ?? 0);
        if ($decimalPosition <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$decimalPosition).$digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $decimalPosition - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $decimalPosition);
            $fraction = substr($digits, $decimalPosition);
        }

        $integerDigits = strlen(ltrim($integer, '0'));
        if ($integerDigits > self::PRODUCTION_DECIMAL_INTEGER_DIGITS) {
            throw new DomainException(
                "{$path} must be non-negative with at most "
                .self::PRODUCTION_DECIMAL_INTEGER_DIGITS.' integer digits.',
            );
        }
        if (strlen(rtrim($fraction, '0')) > self::PRODUCTION_DECIMAL_SCALE) {
            throw new DomainException(
                "{$path} must have at most "
                .self::PRODUCTION_DECIMAL_SCALE
                .' fractional digits and fit decimal(16,4) without rounding.',
            );
        }

        return $value;
    }

    private function persistedString(mixed $value, string $path): string
    {
        $value = $this->string($value, $path);
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > self::POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS) {
            throw new DomainException(
                "{$path} must be at most "
                .self::POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS
                .' characters.',
            );
        }

        return $value;
    }

    private function validateJsonAuthoredValue(mixed $value, string $path): void
    {
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new DomainException("{$path} must contain valid UTF-8.");
            }
            if (str_contains($value, "\0")) {
                throw new DomainException("{$path} must not contain U+0000.");
            }

            return;
        }
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new DomainException("{$path} contains a key that must contain valid UTF-8.");
            }
            if (is_string($key) && str_contains($key, "\0")) {
                throw new DomainException("{$path} contains a key that must not contain U+0000.");
            }
            $this->validateJsonAuthoredValue($nested, "{$path}.{$key}");
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException("{$path} must be a non-empty string.");
        }
        if (preg_match('//u', $value) !== 1) {
            throw new DomainException("{$path} must contain valid UTF-8.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path, ?int $minimum = null): int
    {
        if (! is_int($value)) {
            throw new DomainException("{$path} must be an integer.");
        }
        if ($minimum !== null && $value < $minimum) {
            throw new DomainException("{$path} must be at least {$minimum}.");
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new DomainException("{$path} must be a boolean.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException("{$path} must be a list.");
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new DomainException("{$path} must be an associative array.");
        }

        return $value;
    }
}
