<?php

$behavior = [
    'asset_key',
    'movement_reward_resource_key',
];

$data = [
    'build_cost_money',
    'capacity_per_type',
    'maximum_hp',
    'movement_oil_units',
    'movement_reward_money',
    'movement_reward_resource_units',
    'sort_order',
    'visibility_radius',
];

$flavor = ['name'];

return [
    'payload' => [
        'surface_ships' => [
            'capacity_per_type' => 3,
            'definitions' => [
                'fishing' => [
                    'name' => '漁船',
                    'asset_key' => 'ship.fishing',
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
