<?php

$ruleset = require __DIR__.'/roadmap-pr7-v1.php';

$ruleset['key'] = 'roadmap-pr11-v1';
$ruleset['version'] = 1;
$ruleset['command_queue_limit'] = 30;
$ruleset['inventory_sale_rates'] = [
    'wheat' => ['inventory_units' => 1_000, 'money_units' => 1],
    'fish' => ['inventory_units' => 1_000, 'money_units' => 1],
    'monster_meat' => ['inventory_units' => 1_000, 'money_units' => 2],
    'industrial_goods' => ['inventory_units' => 1_000, 'money_units' => 1],
    'minerals' => ['inventory_units' => 1_000, 'money_units' => 1],
];
$ruleset['facility_definitions']['town'] = [
    'name' => '町', 'asset_key' => 'tile.town', 'visibility_policy' => 'public',
    'build_command_key' => null, 'scale_unit_people' => null, 'initial_scale' => null,
    'scale_increment' => null, 'maximum_scale' => null, 'workforce_per_scale_people' => null,
    'production_definition_key' => null, 'buildable_terrain_keys' => [],
];
$ruleset['facility_definitions']['city'] = [
    'name' => '都市', 'asset_key' => 'tile.city', 'visibility_policy' => 'public',
    'build_command_key' => null, 'scale_unit_people' => null, 'initial_scale' => null,
    'scale_increment' => null, 'maximum_scale' => null, 'workforce_per_scale_people' => null,
    'production_definition_key' => null, 'buildable_terrain_keys' => [],
];

foreach ($ruleset['command_definitions'] as &$commandDefinition) {
    $commandDefinition['metadata']['execution_deferred'] = false;
    if ($commandDefinition['key'] === 'land_level') {
        unset($commandDefinition['metadata']['earthquake_check_deferred']);
    }
}
unset($commandDefinition);

$ruleset['production_definitions'] = [
    ['key' => 'farm_wheat', 'facility_key' => 'farm', 'output_resource_key' => 'wheat', 'production_per_scale' => 1000, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_start_workforce_allocation', 'price_reference' => 'sale.wheat', 'metadata' => ['production_per_worker' => 1, 'canonical_unit' => 'ton']],
    ['key' => 'factory_industrial_goods', 'facility_key' => 'factory', 'output_resource_key' => 'industrial_goods', 'production_per_scale' => 1000, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_start_workforce_allocation', 'price_reference' => 'sale.industrial_goods', 'metadata' => ['production_per_worker' => 1]],
    ['key' => 'mine_minerals', 'facility_key' => 'mine', 'output_resource_key' => 'minerals', 'production_per_scale' => 1000, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_start_workforce_allocation', 'price_reference' => 'sale.minerals', 'metadata' => ['production_per_worker' => 1]],
];

$ruleset['turn_processing'] = [
    'automatic_finance_money' => 10,
    'food' => [
        'population_per_nutrition' => 5,
        'consumption_priority' => ['wheat', 'fish', 'monster_meat'],
    ],
    'workforce' => [
        'priority' => ['farm', 'factory_mine'],
        'farm_output_per_worker' => 1,
        'factory_output_per_worker' => 1,
        'mine_output_per_worker' => 1,
        'allocation_rule' => 'capacity_proportional_largest_remainder',
    ],
    'settlement' => [
        'appearance_probability' => ['numerator' => 20, 'denominator' => 100],
        'initial_population' => 100,
        'eligible_terrain_key' => 'plain',
        'adjacent_facility_key' => 'farm',
        'stages' => [
            'village' => ['facility_key' => 'village', 'minimum_population' => 1, 'maximum_population' => 2999],
            'town' => ['facility_key' => 'town', 'minimum_population' => 3000, 'maximum_population' => 9999],
            'city' => ['facility_key' => 'city', 'minimum_population' => 10000, 'maximum_population' => 2_147_483_647],
        ],
        'sea_edge_bands' => [
            ['minimum_sea_cells' => 24, 'maximum_population' => 10000, 'growth_multiplier' => 3],
            ['minimum_sea_cells' => 12, 'maximum_population' => 5000, 'growth_multiplier' => 2],
            ['minimum_sea_cells' => 0, 'maximum_population' => 2000, 'growth_multiplier' => 1],
        ],
        'ordinary_growth' => ['minimum' => 100, 'maximum' => 300, 'unit_people' => 1],
        'attraction_growth' => ['minimum' => 100, 'maximum' => 1000, 'unit_people' => 1],
        'attraction_maximum_population' => 20000,
    ],
    'famine' => ['loss_minimum' => 100, 'loss_maximum' => 3000, 'loss_unit_people' => 1],
    'riot' => [
        'probability' => ['numerator' => 1, 'denominator' => 4],
        'facility_keys' => ['farm', 'factory', 'missile_base'],
    ],
    'command_random_effects' => [
        'land_clear_buried_treasure' => [
            'probability' => ['numerator' => 10, 'denominator' => 1000],
            'reward_minimum_money' => 100,
            'reward_maximum_money' => 1000,
        ],
    ],
    'sale_policy' => ['sell_all_forbidden_resource_keys' => ['wheat']],
];

return $ruleset;
