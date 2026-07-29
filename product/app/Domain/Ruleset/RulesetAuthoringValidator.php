<?php

namespace App\Domain\Ruleset;

use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Economy\SalePolicy;
use App\Domain\Facility\FacilityVisibilityPolicy;
use DomainException;

final class RulesetAuthoringValidator
{
    private const ARCHITECTURE_CHUNK_SIZE = 16;

    private const INITIAL_X_MIN = 0;

    private const INITIAL_X_MAX = 59;

    private const INITIAL_Y_MIN = 0;

    private const INITIAL_Y_MAX = 59;

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
        $this->requireKeys($settings, self::REQUIRED_TOP_LEVEL_KEYS, 'ruleset');

        $key = $this->string($settings['key'], 'ruleset.key');
        $version = $this->integer($settings['version'], 'ruleset.version', 1);
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

        $this->integer($settings['minimum_capital_distance'], 'ruleset.minimum_capital_distance', 0);
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
        $this->integer($settings['command_queue_limit'], 'ruleset.command_queue_limit', 1);
        foreach ([
            'initial_territory_radius',
            'initial_island_land_radius',
            'initial_island_growth_radius',
            'initial_island_reservation_radius',
            'initial_island_growth_steps',
        ] as $field) {
            $this->integer($settings[$field], "ruleset.{$field}", 0);
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

        $this->validateTerrainQuantities($settings);
        $this->validateFacilities($settings, $commandKeys, $productionKeys);
        $this->validateCommands($settings, $resourceKeys, $facilityKeys);
        $this->validateProduction($settings, $resourceKeys, $facilityKeys);
        $this->validateVersionAdditions($settings, $resourceKeys);

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
            $this->string($definition['key'], "{$path}.key");
            $this->string($definition['name'], "{$path}.name");
            $this->string($definition['category'], "{$path}.category");
            $this->string($definition['unit'], "{$path}.unit");
            if ($definition['nutrition_per_unit'] !== null) {
                $this->integer($definition['nutrition_per_unit'], "{$path}.nutrition_per_unit", 0);
            }
            $this->boolean($definition['storable'], "{$path}.storable");
            $this->boolean($definition['tradable'], "{$path}.tradable");
            $priceKey = $this->string($definition['sale_price_key'], "{$path}.sale_price_key");
            if (! array_key_exists($priceKey, $salePrices)) {
                throw new DomainException("{$path}.sale_price_key references missing price {$priceKey}.");
            }
            $this->integer($definition['sort_order'], "{$path}.sort_order", 0);
            $this->map($definition['metadata'], "{$path}.metadata");
            if (array_key_exists('unit_label', $definition) && $definition['unit_label'] !== null) {
                $this->string($definition['unit_label'], "{$path}.unit_label");
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
            $this->string($quantity['key'], "{$path}.key");
            $this->string($quantity['label'], "{$path}.label");
            $this->string($quantity['unit'], "{$path}.unit");
            $initial = $this->integer($quantity['initial_quantity'], "{$path}.initial_quantity", 0);
            $minimum = $this->integer($quantity['minimum_quantity'], "{$path}.minimum_quantity", 0);
            $maximum = $this->integer($quantity['maximum_quantity'], "{$path}.maximum_quantity", 0);
            $this->integer($quantity['growth_increment'], "{$path}.growth_increment", 0);
            $this->string($quantity['growth_rule_key'], "{$path}.growth_rule_key");
            if ($minimum > $initial || $initial > $maximum) {
                throw new DomainException("{$path} requires minimum <= initial <= maximum.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $commandKeys
     * @param  list<string>  $productionKeys
     */
    private function validateFacilities(array $settings, array $commandKeys, array $productionKeys): void
    {
        foreach ($this->map($settings['facility_definitions'], 'ruleset.facility_definitions') as $key => $definition) {
            $path = "ruleset.facility_definitions.{$key}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'name', 'asset_key', 'visibility_policy', 'build_command_key', 'scale_unit_people',
                'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people',
                'production_definition_key', 'buildable_terrain_keys',
            ], $path);
            $this->string($definition['name'], "{$path}.name");
            $this->string($definition['asset_key'], "{$path}.asset_key");
            $visibilityPolicy = $this->string($definition['visibility_policy'], "{$path}.visibility_policy");
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
            foreach ([
                'scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale',
                'workforce_per_scale_people', 'initial_experience', 'maximum_experience',
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
                $this->string($definition['disguise_asset_key'], "{$path}.disguise_asset_key");
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
            foreach (['key', 'name', 'description', 'target_type', 'execution_phase'] as $field) {
                $this->string($definition[$field], "{$path}.{$field}");
            }
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
            $this->integer($definition['sort_order'], "{$path}.sort_order", 0);
            $this->map($definition['metadata'], "{$path}.metadata");
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
        foreach ($this->list($settings['production_definitions'], 'ruleset.production_definitions') as $index => $definition) {
            $path = "ruleset.production_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'facility_key', 'output_resource_key', 'production_per_scale',
                'required_workforce_per_scale', 'operating_condition', 'price_reference', 'metadata',
            ], $path);
            $this->string($definition['key'], "{$path}.key");
            $this->reference($definition['facility_key'], $facilityKeys, "{$path}.facility_key");
            $this->reference($definition['output_resource_key'], $resourceKeys, "{$path}.output_resource_key");
            if ((! is_int($definition['production_per_scale']) && ! is_float($definition['production_per_scale']))
                || $definition['production_per_scale'] < 0) {
                throw new DomainException("{$path}.production_per_scale must be a non-negative number.");
            }
            $this->integer(
                $definition['required_workforce_per_scale'],
                "{$path}.required_workforce_per_scale",
                0,
            );
            $this->string($definition['operating_condition'], "{$path}.operating_condition");
            $priceReference = $this->string($definition['price_reference'], "{$path}.price_reference");
            if (! array_key_exists($priceReference, $salePrices)) {
                throw new DomainException("{$path}.price_reference references missing price {$priceReference}.");
            }
            $this->map($definition['metadata'], "{$path}.metadata");
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     */
    private function validateVersionAdditions(array $settings, array $resourceKeys): void
    {
        if (array_key_exists('development_plan_quantity', $settings)
            && ! DevelopmentPlanQuantity::matchesContract($settings['development_plan_quantity'])) {
            throw new DomainException('ruleset.development_plan_quantity does not match the canonical quantity contract.');
        }
        foreach (['initial_island_minimum_shallow_cells', 'base_money_capacity', 'base_food_capacity_tons'] as $field) {
            if (array_key_exists($field, $settings)) {
                $this->integer($settings[$field], "ruleset.{$field}", 0);
            }
        }
        if (array_key_exists('inventory_sale_rates', $settings)) {
            foreach ($this->map($settings['inventory_sale_rates'], 'ruleset.inventory_sale_rates') as $resourceKey => $rate) {
                $this->reference($resourceKey, $resourceKeys, 'ruleset.inventory_sale_rates');
                $path = "ruleset.inventory_sale_rates.{$resourceKey}";
                $rate = $this->map($rate, $path);
                $this->requireKeys($rate, ['inventory_units', 'money_units'], $path);
                $this->integer($rate['inventory_units'], "{$path}.inventory_units", 1);
                $this->integer($rate['money_units'], "{$path}.money_units", 0);
            }
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
                $key = $this->string($index, "{$path} key");
                $this->map($definition, "{$path}.{$key}");
            } else {
                $definition = $this->map($definition, "{$path}.{$index}");
                if (! array_key_exists('key', $definition)) {
                    throw new DomainException("{$path}.{$index} is missing required key.");
                }
                $key = $this->string($definition['key'], "{$path}.{$index}.key");
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

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException("{$path} must be a non-empty string.");
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
