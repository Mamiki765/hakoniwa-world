<?php

return [
    'payload' => [
        'central_facilities' => [
            'definitions' => [
                'central_bank' => [
                    'facility_key' => 'central_bank',
                    'capacity_kind' => 'money',
                    'capacity_per_level' => 1000,
                    'maximum_per_nation' => 1,
                ],
                'central_granary' => [
                    'facility_key' => 'central_granary',
                    'capacity_kind' => 'food_tons',
                    'capacity_per_level' => 100000,
                    'maximum_per_nation' => 1,
                ],
            ],
            'natural_monster_hp' => [
                'facility_keys' => [
                    0 => 'central_bank',
                    1 => 'central_granary',
                ],
                'percent_per_level' => 1,
                'level_aggregation' => 'sum',
                'rounding' => 'independent_fractional_draw',
                'draw_denominator' => 100,
                'stream_version' => 1,
            ],
            'forest_equivalent_protection' => [
                'facility_keys' => [
                    0 => 'central_bank',
                    1 => 'central_granary',
                ],
                'disaster_keys' => [
                    0 => 'fire',
                    1 => 'typhoon',
                ],
            ],
            'damage_behavior' => [
                'facility_keys' => [
                    0 => 'central_bank',
                    1 => 'central_granary',
                ],
                'destroyed_terrain_key' => 'shallow',
            ],
            'missile_resistance' => [
                'facility_keys' => [
                    0 => 'central_bank',
                    1 => 'central_granary',
                ],
                'ineffective_missile_keys' => [
                    0 => 'missile',
                    1 => 'pp_missile',
                    2 => 'spp_missile',
                ],
                'land_destruction_missile_key' => 'land_destruction_missile',
                'land_destruction_level_loss' => 1,
            ],
            'disaster_damage' => [
                'facility_keys' => [
                    0 => 'central_bank',
                    1 => 'central_granary',
                ],
                'tsunami_level_loss' => 1,
                'meteor_shower_level_loss' => 5,
                'huge_meteor_center_level_loss' => 20,
                'huge_meteor_ring_one_level_loss' => 5,
                'huge_meteor_ring_two_level_loss' => 1,
                'eruption_center_level_loss' => 5,
                'eruption_ring_one_level_loss' => 1,
                'land_subsidence_level_loss' => 5,
                'immune_disaster_keys' => [
                    0 => 'earthquake',
                ],
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            0 => 'facility_key',
            1 => 'capacity_kind',
            2 => 'maximum_per_nation',
            3 => 'level_aggregation',
            4 => 'rounding',
            5 => 'stream_version',
            6 => '/central_facilities/natural_monster_hp/facility_keys/*',
            7 => '/central_facilities/forest_equivalent_protection/facility_keys/*',
            8 => '/central_facilities/forest_equivalent_protection/disaster_keys/*',
            9 => '/central_facilities/damage_behavior/facility_keys/*',
            10 => '/central_facilities/missile_resistance/facility_keys/*',
            11 => '/central_facilities/missile_resistance/ineffective_missile_keys/*',
            12 => '/central_facilities/disaster_damage/facility_keys/*',
            13 => '/central_facilities/disaster_damage/immune_disaster_keys/*',
            14 => 'land_destruction_missile_key',
            15 => 'destroyed_terrain_key',
        ],
        'data' => [
            0 => 'capacity_per_level',
            1 => 'percent_per_level',
            2 => 'draw_denominator',
            3 => 'land_destruction_level_loss',
            4 => 'tsunami_level_loss',
            5 => 'meteor_shower_level_loss',
            6 => 'huge_meteor_center_level_loss',
            7 => 'huge_meteor_ring_one_level_loss',
            8 => 'huge_meteor_ring_two_level_loss',
            9 => 'eruption_center_level_loss',
            10 => 'eruption_ring_one_level_loss',
            11 => 'land_subsidence_level_loss',
        ],
        'flavor' => [
        ],
    ],
];
