<?php

return [
    'payload' => [
        'ocean_loop' => [
            'npc_ship_spawn' => [
                'probability' => ['numerator' => 1, 'denominator' => 10],
                'world_area_scale' => ['base_chunks' => 225, 'opportunities_per_base' => 16],
                'required_port_facility_key' => 'port',
                'minimum_origin_distance' => 4,
                'maximum_origin_distance' => 6,
                'ship_type_weights' => ['pirate' => 85, 'treasure' => 15],
                'pirate_initial_hp' => ['minimum' => 1, 'maximum' => 3],
                'pirate_initial_population' => ['minimum' => 5000, 'maximum' => 10000],
                'stream_version' => 1,
            ],
            'buried_treasure' => [
                'maximum_stack_per_cell' => 5,
                'remote_reveal_probability' => ['numerator' => 1, 'denominator' => 5],
                'natural_spawn' => [
                    'probability' => ['numerator' => 1, 'denominator' => 100],
                    'world_area_scale' => ['base_chunks' => 225, 'opportunities_per_base' => 16],
                    'terrain_key' => 'sea',
                ],
                'sparkle_asset_key' => 'map.buried_treasure.sparkle',
                'standard_reward' => [
                    'item_key' => 'wakuwaku_ticket', 'quantity' => 1,
                    'rarity' => 'regular', 'fixed_sale_price_money' => 500,
                ],
                'premium_reward' => [
                    'item_key' => 'dokidoki_ticket', 'quantity' => 1,
                    'rarity' => 'high_quality', 'fixed_sale_price_money' => 1500,
                ],
                'stream_version' => 1,
            ],
            'pirate_attack' => [
                'probability' => ['numerator' => 1, 'denominator' => 2],
                'range' => 2,
                'player_ship_damage' => 1,
                'settlement_facility_keys' => ['village', 'town', 'city', 'capital'],
                'seabed_facility_keys' => ['seabed_base', 'undersea_city'],
                'missile_refugee_percent' => 50,
                'warship_refugee_percent' => 100,
                'stream_version' => 1,
            ],
            'warship_attack' => [
                'range' => 5,
                'damage' => 1,
                'shots_per_turn' => 1,
                'cost_money_per_shot' => 20,
                'always_hits' => true,
                'bypasses_defense' => true,
                'target_order' => 'surface_cell_processing_order',
                'target_ship_type_keys' => ['pirate', 'treasure'],
                'fixed_treasure_ship_experience' => 7,
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            '/ocean_loop/buried_treasure/natural_spawn/terrain_key',
            '/ocean_loop/pirate_attack/settlement_facility_keys/*',
            '/ocean_loop/pirate_attack/seabed_facility_keys/*',
            '/ocean_loop/warship_attack/target_ship_type_keys/*',
            'required_port_facility_key', 'stream_version', 'sparkle_asset_key',
            'item_key', 'rarity', 'target_order', 'always_hits', 'bypasses_defense',
        ],
        'data' => [
            'numerator', 'denominator', 'base_chunks', 'opportunities_per_base',
            'minimum_origin_distance', 'maximum_origin_distance', 'minimum', 'maximum',
            'maximum_stack_per_cell', 'quantity', 'fixed_sale_price_money', 'range',
            'player_ship_damage', 'missile_refugee_percent', 'warship_refugee_percent',
            'damage', 'shots_per_turn', 'cost_money_per_shot', 'fixed_treasure_ship_experience',
            '/ocean_loop/npc_ship_spawn/ship_type_weights/pirate',
            '/ocean_loop/npc_ship_spawn/ship_type_weights/treasure',
        ],
        'flavor' => [],
    ],
];
