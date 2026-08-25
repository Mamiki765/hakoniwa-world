<?php

$behavior = [
    0 => '/secretary/items/old_bow/effects/*/target_map_space_keys/*',
    1 => '/secretary/items/secretary_suit/effects/*/sources/*',
    2 => '/secretary/skills/forest_management/experience_source/command_keys/*',
    3 => 'basis',
    4 => 'category',
    5 => 'command_key',
    6 => 'damage_type',
    7 => 'draw_unit',
    8 => 'exclude_monster_occupied_cells',
    9 => 'forest_growth_base',
    10 => 'historical_backfill',
    11 => 'include_actual_impact',
    12 => 'include_normal_defense_interception',
    13 => 'include_secretary_interception',
    14 => 'include_self_fired_collateral',
    15 => 'independent_from_interception_eligibility',
    16 => 'key',
    17 => 'level_source',
    18 => 'logging_base',
    19 => 'normal_defense_resolves_first',
    20 => 'npc_tradable',
    21 => 'quantity_multiplier',
    22 => 'random_stream_version',
    23 => 'rarity',
    24 => 'resource_key',
    25 => 'rounding',
    26 => 'snapshot_timing',
    27 => 'source_genre',
    28 => 'stacking',
    29 => 'target',
    30 => 'target_safety_policy',
    31 => 'target_scope',
    32 => 'timing',
    33 => 'tradable',
    34 => 'type',
];

$data = [
    0 => 'bonus_money_per_level',
    1 => 'cap',
    2 => 'chance_basis_points',
    3 => 'chance_percent_per_level',
    4 => 'damage',
    5 => 'food_percent_per_level',
    6 => 'initial_level',
    7 => 'interceptions_per_level_per_turn',
    8 => 'lower_minimum_per_level',
    9 => 'max_equipped',
    10 => 'max_level',
    11 => 'minimum_final_probability',
    12 => 'money_percent_per_level',
    13 => 'multiplier',
    14 => 'per_mille_per_level',
    15 => 'percent_per_level',
    16 => 'points_per_execution',
    17 => 'points_per_missile',
];

$flavor = [
    0 => 'name',
];

return [
    'payload' => [
        'secretary' => [
            'capacity_bonus' => [
                'level_source' => 'sum_passive_skill_levels',
                'money_percent_per_level' => 1,
                'food_percent_per_level' => 1,
                'rounding' => 'floor_after_multiplier',
                'cap' => null,
            ],
            'skills' => [
                'agricultural_policy' => [
                    'key' => 'agricultural_policy',
                    'name' => '農業政策',
                    'initial_level' => 0,
                    'level_requirement' => [
                        'basis' => 'next_level_squared',
                        'multiplier' => 1,
                    ],
                    'effect' => [
                        'type' => 'production_multiplier',
                        'resource_key' => 'wheat',
                        'per_mille_per_level' => 1,
                    ],
                    'experience_source' => [
                        'type' => 'successful_command_execution',
                        'command_key' => 'build_farm',
                        'points_per_execution' => 1,
                        'quantity_multiplier' => false,
                    ],
                ],
                'specialty_development' => [
                    'key' => 'specialty_development',
                    'name' => '特産品開発',
                    'initial_level' => 0,
                    'level_requirement' => [
                        'basis' => 'next_level_squared',
                        'multiplier' => 1,
                    ],
                    'effect' => [
                        'type' => 'production_multiplier',
                        'resource_key' => 'industrial_goods',
                        'per_mille_per_level' => 1,
                    ],
                    'experience_source' => [
                        'type' => 'successful_command_execution',
                        'command_key' => 'build_factory',
                        'points_per_execution' => 1,
                        'quantity_multiplier' => false,
                    ],
                ],
                'gold_vein_survey' => [
                    'key' => 'gold_vein_survey',
                    'name' => '金鉱脈調査',
                    'initial_level' => 0,
                    'level_requirement' => [
                        'basis' => 'next_level_squared',
                        'multiplier' => 1,
                    ],
                    'effect' => [
                        'type' => 'production_multiplier',
                        'resource_key' => 'minerals',
                        'per_mille_per_level' => 1,
                    ],
                    'experience_source' => [
                        'type' => 'successful_command_execution',
                        'command_key' => 'build_mine',
                        'points_per_execution' => 1,
                        'quantity_multiplier' => false,
                    ],
                ],
                'forest_management' => [
                    'key' => 'forest_management',
                    'name' => '森林管理',
                    'initial_level' => 0,
                    'level_requirement' => [
                        'basis' => 'next_level_squared',
                        'multiplier' => 1,
                    ],
                    'effect' => [
                        'type' => 'forest_management',
                        'percent_per_level' => 1,
                        'rounding' => 'floor_after_multiplier',
                        'logging_base' => 'canonical_logging_income',
                        'forest_growth_base' => 'terrain_quantities.forest.growth_increment',
                    ],
                    'experience_source' => [
                        'type' => 'successful_command_execution',
                        'command_keys' => [
                            0 => 'logging',
                            1 => 'plant_forest',
                        ],
                        'points_per_execution' => 1,
                        'quantity_multiplier' => false,
                        'historical_backfill' => false,
                    ],
                ],
                'final_defense_line' => [
                    'key' => 'final_defense_line',
                    'name' => '最終防衛ライン',
                    'initial_level' => 1,
                    'level_requirement' => [
                        'basis' => 'current_level_squared',
                        'multiplier' => 100,
                    ],
                    'effect' => [
                        'type' => 'final_defense_line',
                        'interceptions_per_level_per_turn' => 1,
                        'normal_defense_resolves_first' => true,
                        'exclude_monster_occupied_cells' => true,
                    ],
                    'experience_source' => [
                        'type' => 'owned_cell_missile_arrival',
                        'points_per_missile' => 1,
                        'include_normal_defense_interception' => true,
                        'include_secretary_interception' => true,
                        'include_actual_impact' => true,
                        'include_self_fired_collateral' => true,
                        'independent_from_interception_eligibility' => true,
                    ],
                ],
            ],
            'item_rarities' => [
                'novice' => [
                    'key' => 'novice',
                    'name' => 'ノービス',
                ],
            ],
            'item_categories' => [
                'accessory' => [
                    'key' => 'accessory',
                    'max_equipped' => 99,
                ],
                'bow' => [
                    'key' => 'bow',
                    'max_equipped' => 1,
                ],
                'clothing' => [
                    'key' => 'clothing',
                    'max_equipped' => 1,
                ],
            ],
            'items' => [
                'old_bow' => [
                    'key' => 'old_bow',
                    'category' => 'bow',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => false,
                    'max_level' => 1,
                    'effects' => [
                        0 => [
                            'type' => 'pre_normal_monster_attack',
                            'timing' => 'after_missile_finalization_before_normal_monsters',
                            'chance_basis_points' => 1000,
                            'damage' => 1,
                            'damage_type' => 'secretary_old_bow',
                            'target_scope' => 'owned_territory',
                            'target_map_space_keys' => [
                                0 => 'surface',
                            ],
                            'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
                            'random_stream_version' => 1,
                        ],
                    ],
                ],
                'ring' => [
                    'key' => 'ring',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'finance_income_bonus',
                            'bonus_money_per_level' => 1,
                            'stacking' => 'sum_equipped_levels',
                        ],
                    ],
                ],
                'secretary_suit' => [
                    'key' => 'secretary_suit',
                    'category' => 'clothing',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'secretary_experience_double_chance',
                            'chance_percent_per_level' => 1,
                            'multiplier' => 2,
                            'sources' => [
                                0 => 'passive_skill_experience',
                                1 => 'monster_experience',
                            ],
                            'draw_unit' => 'canonical_award_event',
                            'random_stream_version' => 1,
                        ],
                    ],
                ],
                'inora_bracelet' => [
                    'key' => 'inora_bracelet',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'natural_monster_spawn_percent',
                            'source_genre' => 'item',
                            'target' => 'normal_nation_natural_spawn',
                            'percent_per_level' => 10,
                            'minimum_final_probability' => 0,
                        ],
                    ],
                ],
                'hoarder_talisman' => [
                    'key' => 'hoarder_talisman',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'capacity_percent',
                            'source_genre' => 'item',
                            'target' => 'all_nation_resources',
                            'percent_per_level' => 1,
                            'rounding' => 'floor_after_all_source_genres',
                        ],
                    ],
                ],
                'good_person_treasure' => [
                    'key' => 'good_person_treasure',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 20,
                    'effects' => [
                        0 => [
                            'type' => 'karma_minimum_delta',
                            'lower_minimum_per_level' => 1,
                            'snapshot_timing' => 'turn_start',
                        ],
                    ],
                ],
                'vault_key' => [
                    'key' => 'vault_key',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'capacity_percent',
                            'source_genre' => 'item',
                            'target' => 'money',
                            'percent_per_level' => 1,
                            'rounding' => 'floor_after_all_source_genres',
                        ],
                    ],
                ],
                'monster_repellent_incense' => [
                    'key' => 'monster_repellent_incense',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'natural_monster_spawn_percent',
                            'source_genre' => 'item',
                            'target' => 'normal_nation_natural_spawn',
                            'percent_per_level' => -1,
                            'minimum_final_probability' => 0,
                        ],
                    ],
                ],
                'fullness_herb' => [
                    'key' => 'fullness_herb',
                    'category' => 'accessory',
                    'rarity' => 'novice',
                    'tradable' => true,
                    'npc_tradable' => true,
                    'max_level' => 10,
                    'effects' => [
                        0 => [
                            'type' => 'capacity_percent',
                            'source_genre' => 'item',
                            'target' => 'food_aggregate',
                            'percent_per_level' => 2,
                            'rounding' => 'floor_after_all_source_genres',
                        ],
                    ],
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
