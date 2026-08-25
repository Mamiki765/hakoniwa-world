<?php

$behavior = [
    0 => '/turn_processing/command_random_effects/land_level_earthquake/facility_keys/*',
    1 => '/turn_processing/disasters/earthquake/facility_keys/*',
    2 => '/turn_processing/disasters/eruption/seabed_facility_keys/*',
    3 => '/turn_processing/disasters/fire/facility_keys/*',
    4 => '/turn_processing/disasters/fire/protection_facility_keys/*',
    5 => '/turn_processing/disasters/huge_meteor/seabed_facility_keys/*',
    6 => '/turn_processing/disasters/meteor_shower/seabed_facility_keys/*',
    7 => '/turn_processing/disasters/tsunami/excluded_facility_keys/*',
    8 => '/turn_processing/disasters/tsunami/facility_keys/*',
    9 => '/turn_processing/disasters/tsunami/settlement_facility_keys/*',
    10 => '/turn_processing/disasters/tsunami/water_facility_keys/*',
    11 => '/turn_processing/disasters/typhoon/facility_keys/*',
    12 => '/turn_processing/disasters/typhoon/protection_facility_keys/*',
    13 => 'affected_coastal_land_result',
    14 => 'affected_shallow_result',
    15 => 'depleted_terrain_key',
    16 => 'enabled',
    17 => 'facility_key',
    18 => 'growth_rule_key',
    19 => 'key',
    20 => 'mountain_immune',
    21 => 'out_of_bounds_is_water',
    22 => 'output_resource_key',
    23 => 'stream_version',
];

$data = [
    0 => 'adjacent_water_offset',
    1 => 'base_damage_threshold',
    2 => 'base_safe_land_cells',
    3 => 'capital_damage_percentage',
    4 => 'capital_growth_maximum_population',
    5 => 'center_padding',
    6 => 'deep_sea',
    7 => 'denominator',
    8 => 'draw_denominator',
    9 => 'eruption_center',
    10 => 'excavation_or_shallow',
    11 => 'facility_or_wasteland',
    12 => 'growth_increment',
    13 => 'initial_quantity',
    14 => 'internal_denominator',
    15 => 'maximum_quantity',
    16 => 'minimum_city_population',
    17 => 'minimum_quantity',
    18 => 'numerator',
    19 => 'production_units',
    20 => 'radius',
    21 => 'reward_maximum_money',
    22 => 'reward_minimum_money',
    23 => 'success_threshold_per_cost_unit',
];

$flavor = [
    0 => 'label',
    1 => 'unit',
];

return [
    'payload' => [
        'terrain_quantities' => [
            'forest' => [
                'key' => 'trees',
                'label' => '木',
                'unit' => '本',
                'initial_quantity' => 500,
                'minimum_quantity' => 0,
                'maximum_quantity' => 20000,
                'growth_increment' => 100,
                'growth_rule_key' => 'legacy.forest.grow_each_turn',
            ],
        ],
        'turn_processing' => [
            'command_random_effects' => [
                'land_clear_buried_treasure' => [
                    'probability' => [
                        'numerator' => 10,
                        'denominator' => 1000,
                    ],
                    'reward_minimum_money' => 100,
                    'reward_maximum_money' => 1000,
                ],
                'seabed_oil_search' => [
                    'facility_key' => 'seabed_oil_field',
                    'draw_denominator' => 100,
                    'success_threshold_per_cost_unit' => 1,
                ],
                'land_level_earthquake' => [
                    'probability' => [
                        'numerator' => 5,
                        'denominator' => 2000,
                    ],
                    'radius' => 10,
                    'minimum_city_population' => 10000,
                    'facility_keys' => [
                        0 => 'factory',
                        1 => 'decoy',
                    ],
                    'damage_probability' => [
                        'numerator' => 1,
                        'denominator' => 4,
                    ],
                ],
            ],
            'disasters' => [
                'earthquake' => [
                    'probability' => [
                        'numerator' => 80,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 5,
                    'radius' => 10,
                    'minimum_city_population' => 10000,
                    'facility_keys' => [
                        0 => 'factory',
                        1 => 'decoy',
                    ],
                    'damage_probability' => [
                        'numerator' => 1,
                        'denominator' => 4,
                    ],
                ],
                'tsunami' => [
                    'probability' => [
                        'numerator' => 300,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 5,
                    'radius' => 10,
                    'settlement_facility_keys' => [
                        0 => 'village',
                        1 => 'town',
                        2 => 'city',
                        3 => 'capital',
                    ],
                    'facility_keys' => [
                        0 => 'farm',
                        1 => 'factory',
                        2 => 'missile_base',
                        3 => 'defense',
                        4 => 'decoy',
                    ],
                    'excluded_facility_keys' => [
                        0 => 'seabed_base',
                        1 => 'monument',
                    ],
                    'water_facility_keys' => [
                        0 => 'seabed_base',
                    ],
                    'internal_denominator' => 12,
                    'adjacent_water_offset' => 1,
                ],
                'typhoon' => [
                    'probability' => [
                        'numerator' => 400,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 5,
                    'radius' => 10,
                    'facility_keys' => [
                        0 => 'farm',
                        1 => 'decoy',
                    ],
                    'internal_denominator' => 12,
                    'base_damage_threshold' => 6,
                    'protection_facility_keys' => [
                        0 => 'monument',
                    ],
                ],
                'meteor_shower' => [
                    'probability' => [
                        'numerator' => 200,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 5,
                    'radius' => 10,
                    'continuation_probability' => [
                        'numerator' => 1,
                        'denominator' => 2,
                    ],
                    'seabed_facility_keys' => [
                        0 => 'seabed_oil_field',
                        1 => 'seabed_base',
                    ],
                ],
                'huge_meteor' => [
                    'probability' => [
                        'numerator' => 100,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 2,
                    'radius' => 2,
                    'seabed_facility_keys' => [
                        0 => 'seabed_oil_field',
                        1 => 'seabed_base',
                    ],
                ],
                'eruption' => [
                    'probability' => [
                        'numerator' => 200,
                        'denominator' => 2000,
                    ],
                    'center_padding' => 0,
                    'radius' => 1,
                    'seabed_facility_keys' => [
                        0 => 'seabed_oil_field',
                        1 => 'seabed_base',
                    ],
                ],
                'fire' => [
                    'probability' => [
                        'numerator' => 10,
                        'denominator' => 2000,
                    ],
                    'minimum_city_population' => 10000,
                    'facility_keys' => [
                        0 => 'factory',
                        1 => 'decoy',
                    ],
                    'protection_facility_keys' => [
                        0 => 'monument',
                    ],
                ],
                'land_subsidence' => [
                    'enabled' => true,
                    'base_safe_land_cells' => 100,
                    'probability' => [
                        'numerator' => 2,
                        'denominator' => 100,
                    ],
                    'affected_shallow_result' => 'sea',
                    'affected_coastal_land_result' => 'shallow',
                    'mountain_immune' => true,
                    'capital_damage_percentage' => 30,
                    'out_of_bounds_is_water' => true,
                    'stream_version' => 1,
                ],
            ],
            'oil_field' => [
                'facility_key' => 'seabed_oil_field',
                'output_resource_key' => 'oil',
                'production_units' => 500,
                'depletion_probability' => [
                    'numerator' => 40,
                    'denominator' => 1000,
                ],
                'depleted_terrain_key' => 'sea',
            ],
        ],
        'capital_growth_maximum_population' => 25000,
        'capital_damage_percentages' => [
            'facility_or_wasteland' => 10,
            'excavation_or_shallow' => 30,
            'deep_sea' => 90,
            'eruption_center' => 30,
        ],
    ],
    'classification' => [
        'behavior' => $behavior,
        'data' => $data,
        'flavor' => $flavor,
    ],
];
