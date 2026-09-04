<?php

$behavior = [
    'asset_key',
    'build_selector',
    'fuel_resource_key',
    'movement_reward_resource_key',
    'required_port_facility_key',
    'secretary_skill_key',
    'terrain_key',
    '/surface_ships/forced_displacement/port_search_distances/*',
];

$data = [
    'build_cost_money',
    'capacity_per_type',
    'maximum_hp',
    'movement_oil_units',
    'movement_reward_money',
    'movement_reward_resource_units',
    'normal_event_limit_per_turn',
    'fuel_shortage_damage',
    'fuel_shortage_damage_chance_percent',
    'foreign_destroy_karma',
    'random_stream_version',
    'secretary_experience_per_successful_move',
    'sort_order',
    'visibility_radius',
];

$flavor = ['name'];

return [
    'payload' => [
        'surface_ships' => [
            'capacity_per_type' => 3,
            'movement' => [
                'terrain_key' => 'sea',
                'required_port_facility_key' => 'port',
                'fuel_resource_key' => 'oil',
                'normal_event_limit_per_turn' => 1,
                'fuel_shortage_damage_chance_percent' => 1,
                'fuel_shortage_damage' => 1,
                'random_stream_version' => 1,
                'secretary_skill_key' => 'ship_operations',
                'secretary_experience_per_successful_move' => 1,
            ],
            'forced_displacement' => [
                'port_search_distances' => [1, 2],
                'foreign_destroy_karma' => 1,
            ],
            'definitions' => [
                'fishing' => [
                    'name' => '漁船',
                    'asset_key' => 'ship.fishing',
                    'build_selector' => 1,
                    'sort_order' => 10,
                    'build_cost_money' => 500,
                    'maximum_hp' => 1,
                    'movement_oil_units' => 1,
                    'movement_reward_resource_key' => 'fish',
                    'movement_reward_resource_units' => 7000,
                    'movement_reward_money' => 0,
                    'visibility_radius' => 1,
                ],
                'tourist' => [
                    'name' => '観光船',
                    'asset_key' => 'ship.tourist',
                    'build_selector' => 2,
                    'sort_order' => 20,
                    'build_cost_money' => 1500,
                    'maximum_hp' => 2,
                    'movement_oil_units' => 2,
                    'movement_reward_resource_key' => null,
                    'movement_reward_resource_units' => 0,
                    'movement_reward_money' => 20,
                    'visibility_radius' => 1,
                ],
                'exploration' => [
                    'name' => '探索船',
                    'asset_key' => 'ship.exploration',
                    'build_selector' => 3,
                    'sort_order' => 30,
                    'build_cost_money' => 1000,
                    'maximum_hp' => 2,
                    'movement_oil_units' => 1,
                    'movement_reward_resource_key' => null,
                    'movement_reward_resource_units' => 0,
                    'movement_reward_money' => 0,
                    'visibility_radius' => 3,
                ],
            ],
        ],
    ],
    'classification' => [
        'behavior' => $behavior,
        'data' => $data,
        'flavor' => $flavor,
    ],
];
