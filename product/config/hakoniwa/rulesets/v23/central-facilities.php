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
                'facility_keys' => ['central_bank', 'central_granary'],
                'percent_per_level' => 1,
                'level_aggregation' => 'sum',
                'rounding' => 'independent_fractional_draw',
                'draw_denominator' => 100,
                'stream_version' => 1,
            ],
            'forest_equivalent_protection' => [
                'facility_keys' => ['central_bank', 'central_granary'],
                'disaster_keys' => ['fire', 'typhoon'],
            ],
            'damage_behavior' => [
                'facility_keys' => ['central_bank', 'central_granary'],
                'destroyed_terrain_key' => 'shallow',
            ],
            'missile_resistance' => [
                'facility_keys' => ['central_bank', 'central_granary'],
                'ineffective_missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
                'land_destruction_missile_key' => 'land_destruction_missile',
                'land_destruction_level_loss' => 1,
            ],
            'disaster_damage' => [
                'facility_keys' => ['central_bank', 'central_granary'],
                'tsunami_level_loss' => 1,
                'meteor_shower_level_loss' => 5,
                'huge_meteor_center_level_loss' => 20,
                'huge_meteor_ring_one_level_loss' => 5,
                'huge_meteor_ring_two_level_loss' => 1,
                'eruption_center_level_loss' => 5,
                'eruption_ring_one_level_loss' => 1,
                'land_subsidence_level_loss' => 5,
                'immune_disaster_keys' => ['earthquake'],
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            'facility_key', 'capacity_kind', 'maximum_per_nation', 'level_aggregation', 'rounding', 'stream_version',
            '/central_facilities/natural_monster_hp/facility_keys/*',
            '/central_facilities/forest_equivalent_protection/facility_keys/*',
            '/central_facilities/forest_equivalent_protection/disaster_keys/*',
            '/central_facilities/damage_behavior/facility_keys/*',
            '/central_facilities/missile_resistance/facility_keys/*',
            '/central_facilities/missile_resistance/ineffective_missile_keys/*',
            '/central_facilities/disaster_damage/facility_keys/*',
            '/central_facilities/disaster_damage/immune_disaster_keys/*',
            'land_destruction_missile_key', 'destroyed_terrain_key',
        ],
        'data' => [
            'capacity_per_level', 'percent_per_level', 'draw_denominator', 'land_destruction_level_loss',
            'tsunami_level_loss', 'meteor_shower_level_loss', 'huge_meteor_center_level_loss',
            'huge_meteor_ring_one_level_loss', 'huge_meteor_ring_two_level_loss',
            'eruption_center_level_loss', 'eruption_ring_one_level_loss', 'land_subsidence_level_loss',
        ],
        'flavor' => [],
    ],
];
