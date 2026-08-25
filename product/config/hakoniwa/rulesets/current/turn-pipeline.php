<?php

$behavior = [
    0 => '/turn_processing/food/consumption_priority/*',
    1 => '/turn_processing/riot/facility_keys/*',
    2 => '/turn_processing/sale_policy/sell_all_forbidden_resource_keys/*',
    3 => '/turn_processing/territory_influence/owner_states/*',
    4 => '/turn_processing/territory_influence/source/excluded_facility_keys/*',
    5 => '/turn_processing/territory_influence/source/excluded_terrain_keys/*',
    6 => '/turn_processing/territory_influence/target/excluded_facility_keys/*',
    7 => '/turn_processing/territory_influence/target/excluded_terrain_keys/*',
    8 => '/turn_processing/territory_influence/target/facility_keys/*',
    9 => '/turn_processing/territory_influence/target/unfacilitated_terrain_keys/*',
    10 => '/turn_processing/workforce/priority/*',
    11 => 'adjacent_facility_key',
    12 => 'after',
    13 => 'allocation_rule',
    14 => 'before',
    15 => 'capital_core',
    16 => 'cell_visit_order',
    17 => 'direction_stream',
    18 => 'directions',
    19 => 'eligible_terrain_key',
    20 => 'enabled',
    21 => 'facility',
    22 => 'facility_key',
    23 => 'facility_scale',
    24 => 'monster_occupancy',
    25 => 'monument',
    26 => 'mutation_timing',
    27 => 'normal_monster_stage',
    28 => 'owner',
    29 => 'population',
    30 => 'production_overflow_resolution_stage',
    31 => 'reroll_on_missing_or_ineligible',
    32 => 'resource_and_state',
    33 => 'selection',
    34 => 'source_state',
    35 => 'terrain',
    36 => 'policy_version',
];

$data = [
    0 => 'attempts_per_eligible_target',
    1 => 'attraction_maximum_population',
    2 => 'automatic_finance_money',
    3 => 'denominator',
    4 => 'factory_output_per_worker',
    5 => 'farm_output_per_worker',
    6 => 'initial_population',
    7 => 'loss_maximum',
    8 => 'loss_minimum',
    9 => 'loss_unit_people',
    10 => 'maximum',
    11 => 'maximum_population',
    12 => 'mine_output_per_worker',
    13 => 'minimum',
    14 => 'minimum_population',
    15 => 'numerator',
    16 => 'ordinary_maximum_population',
    17 => 'population_per_nutrition',
    18 => 'unit_people',
];

$flavor = [
];

return [
    'payload' => [
        'turn_processing' => [
            'automatic_finance_money' => 10,
            'food' => [
                'population_per_nutrition' => 5,
                'consumption_priority' => [
                    0 => 'wheat',
                    1 => 'fish',
                    2 => 'monster_meat',
                ],
                'production_overflow_resolution_stage' => 'after_population_nutrition_consumption',
            ],
            'workforce' => [
                'priority' => [
                    0 => 'farm',
                    1 => 'factory_mine',
                ],
                'farm_output_per_worker' => 1,
                'factory_output_per_worker' => 1,
                'mine_output_per_worker' => 1,
                'allocation_rule' => 'capacity_proportional_largest_remainder',
            ],
            'settlement' => [
                'appearance_probability' => [
                    'numerator' => 20,
                    'denominator' => 100,
                ],
                'initial_population' => 100,
                'eligible_terrain_key' => 'plain',
                'adjacent_facility_key' => 'farm',
                'stages' => [
                    'village' => [
                        'facility_key' => 'village',
                        'minimum_population' => 1,
                        'maximum_population' => 2999,
                    ],
                    'town' => [
                        'facility_key' => 'town',
                        'minimum_population' => 3000,
                        'maximum_population' => 9999,
                    ],
                    'city' => [
                        'facility_key' => 'city',
                        'minimum_population' => 10000,
                        'maximum_population' => 2147483647,
                    ],
                ],
                'ordinary_growth' => [
                    'minimum' => 100,
                    'maximum' => 1000,
                    'unit_people' => 1,
                ],
                'attraction_growth' => [
                    'minimum' => 100,
                    'maximum' => 3000,
                    'unit_people' => 1,
                ],
                'attraction_maximum_population' => 20000,
                'post_ordinary_attraction_growth' => [
                    'minimum' => 100,
                    'maximum' => 300,
                    'unit_people' => 1,
                ],
                'ordinary_maximum_population' => 10000,
            ],
            'famine' => [
                'loss_minimum' => 100,
                'loss_maximum' => 3000,
                'loss_unit_people' => 1,
            ],
            'riot' => [
                'probability' => [
                    'numerator' => 1,
                    'denominator' => 4,
                ],
                'facility_keys' => [
                    0 => 'farm',
                    1 => 'factory',
                    2 => 'missile_base',
                    3 => 'seabed_base',
                    4 => 'defense',
                    5 => 'decoy',
                ],
            ],
            'sale_policy' => [
                'sell_all_forbidden_resource_keys' => [
                    0 => 'wheat',
                ],
            ],
            'resource_sale_phase' => [
                'after' => 'nation_economy',
                'before' => 'development_commands',
            ],
            'territory_influence' => [
                'enabled' => true,
                'policy_version' => 1,
                'owner_states' => [
                    0 => 'active',
                ],
                'target' => [
                    'unfacilitated_terrain_keys' => [
                        0 => 'forest',
                        1 => 'mountain',
                    ],
                    'facility_keys' => [
                        0 => 'village',
                        1 => 'town',
                        2 => 'city',
                        3 => 'farm',
                        4 => 'factory',
                        5 => 'mine',
                        6 => 'missile_base',
                        7 => 'defense',
                    ],
                    'excluded_terrain_keys' => [
                        0 => 'sea',
                        1 => 'shallow',
                        2 => 'wasteland',
                        3 => 'scorched',
                    ],
                    'excluded_facility_keys' => [
                        0 => 'seabed_base',
                        1 => 'seabed_oil_field',
                        2 => 'monument',
                    ],
                    'monster_occupancy' => 'exclude',
                    'capital_core' => 'exclude',
                ],
                'source' => [
                    'excluded_terrain_keys' => [
                        0 => 'sea',
                        1 => 'shallow',
                        2 => 'wasteland',
                        3 => 'scorched',
                    ],
                    'excluded_facility_keys' => [
                        0 => 'seabed_base',
                        1 => 'seabed_oil_field',
                    ],
                    'monster_occupancy' => 'exclude',
                    'monument' => 'allowed',
                ],
                'neighbor' => [
                    'directions' => 6,
                    'selection' => 'uniform_one',
                    'reroll_on_missing_or_ineligible' => false,
                ],
                'resolution' => [
                    'cell_visit_order' => 'shared_surface_shuffle_once',
                    'attempts_per_eligible_target' => 1,
                    'source_state' => 'evaluate_at_visit',
                    'mutation_timing' => 'immediate',
                    'direction_stream' => 'territory_influence:direction:v1',
                ],
                'effect' => [
                    'owner' => 'source_owner',
                    'terrain' => 'preserve',
                    'population' => 'preserve',
                    'facility' => 'preserve',
                    'facility_scale' => 'preserve',
                    'resource_and_state' => 'preserve',
                ],
            ],
        ],
        'turn_resolution' => [
            'normal_monster_stage' => 'after_ordinary_surface_cell_events',
        ],
    ],
    'classification' => [
        'behavior' => $behavior,
        'data' => $data,
        'flavor' => $flavor,
    ],
];
