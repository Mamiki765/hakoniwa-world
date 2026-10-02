<?php

return [
    'payload' => [
        'ocean_loop' => [
            'npc_ship_spawn' => [
                'probability' => [
                    'numerator' => 1,
                    'denominator' => 10,
                ],
                'world_area_scale' => [
                    'base_chunks' => 225,
                    'opportunities_per_base' => 16,
                ],
                'required_port_facility_key' => 'port',
                'minimum_origin_distance' => 4,
                'maximum_origin_distance' => 6,
                'ship_type_weights' => [
                    'pirate' => 85,
                    'treasure' => 15,
                ],
                'pirate_initial_hp' => [
                    'minimum' => 1,
                    'maximum' => 3,
                ],
                'pirate_initial_population' => [
                    'minimum' => 5000,
                    'maximum' => 10000,
                ],
                'stream_version' => 1,
            ],
            'buried_treasure' => [
                'maximum_stack_per_cell' => 5,
                'remote_reveal_probability' => [
                    'numerator' => 1,
                    'denominator' => 5,
                ],
                'natural_spawn' => [
                    'probability' => [
                        'numerator' => 1,
                        'denominator' => 100,
                    ],
                    'world_area_scale' => [
                        'base_chunks' => 225,
                        'opportunities_per_base' => 16,
                    ],
                    'terrain_key' => 'sea',
                ],
                'sparkle_asset_key' => 'map.buried_treasure.sparkle',
                'standard_reward' => [
                    'item_key' => 'wakuwaku_ticket',
                    'quantity' => 1,
                    'rarity' => 'regular',
                    'fixed_sale_price_money' => 500,
                ],
                'premium_reward' => [
                    'item_key' => 'dokidoki_ticket',
                    'quantity' => 1,
                    'rarity' => 'high_quality',
                    'fixed_sale_price_money' => 1500,
                ],
                'stream_version' => 1,
                'emblem_replacements' => [
                    'meteor' => [
                        'chance_percent' => 5,
                        'reward' => [
                            'item_key' => 'twin_star_emblem',
                            'quantity' => 1,
                            'rarity' => 'artifact',
                            'fixed_sale_price_money' => 3000,
                        ],
                    ],
                    'pirate_sink' => [
                        'minimum_population' => 20000,
                        'reward' => [
                            'item_key' => 'crescent_emblem',
                            'quantity' => 1,
                            'rarity' => 'artifact',
                            'fixed_sale_price_money' => 3000,
                        ],
                    ],
                ],
            ],
            'pirate_attack' => [
                'probability' => [
                    'numerator' => 1,
                    'denominator' => 2,
                ],
                'range' => 2,
                'player_ship_damage' => 1,
                'settlement_facility_keys' => [
                    0 => 'village',
                    1 => 'town',
                    2 => 'city',
                    3 => 'capital',
                ],
                'seabed_facility_keys' => [
                    0 => 'seabed_base',
                    1 => 'undersea_city',
                ],
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
                'target_ship_type_keys' => [
                    0 => 'pirate',
                    1 => 'treasure',
                ],
                'fixed_treasure_ship_experience' => 7,
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            0 => '/ocean_loop/buried_treasure/natural_spawn/terrain_key',
            1 => '/ocean_loop/pirate_attack/settlement_facility_keys/*',
            2 => '/ocean_loop/pirate_attack/seabed_facility_keys/*',
            3 => '/ocean_loop/warship_attack/target_ship_type_keys/*',
            4 => 'required_port_facility_key',
            5 => 'stream_version',
            6 => 'sparkle_asset_key',
            7 => 'item_key',
            8 => 'rarity',
            9 => 'target_order',
            10 => 'always_hits',
            11 => 'bypasses_defense',
        ],
        'data' => [
            0 => 'numerator',
            1 => 'denominator',
            2 => 'base_chunks',
            3 => 'opportunities_per_base',
            4 => 'minimum_origin_distance',
            5 => 'maximum_origin_distance',
            6 => 'minimum',
            7 => 'maximum',
            8 => 'maximum_stack_per_cell',
            9 => 'quantity',
            10 => 'fixed_sale_price_money',
            11 => 'range',
            12 => 'player_ship_damage',
            13 => 'missile_refugee_percent',
            14 => 'warship_refugee_percent',
            15 => 'damage',
            16 => 'shots_per_turn',
            17 => 'cost_money_per_shot',
            18 => 'fixed_treasure_ship_experience',
            19 => '/ocean_loop/npc_ship_spawn/ship_type_weights/pirate',
            20 => '/ocean_loop/npc_ship_spawn/ship_type_weights/treasure',
            21 => '/ocean_loop/buried_treasure/emblem_replacements/meteor/chance_percent',
            22 => '/ocean_loop/buried_treasure/emblem_replacements/pirate_sink/minimum_population',
        ],
        'flavor' => [
        ],
    ],
];
