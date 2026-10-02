<?php

return [
    'payload' => [
        'underground_facility_development' => [
            'facility_definitions' => [
                'underground_city' => [
                    'name' => '地底都市',
                    'effect' => [
                        'capital_maximum_population_bonus' => 10000,
                    ],
                ],
                'underground_farm' => [
                    'name' => '地底農場',
                    'effect' => [
                        'farm_capacity_people' => 10000,
                    ],
                ],
                'underground_factory' => [
                    'name' => '地底工場',
                    'effect' => [
                        'factory_capacity_people' => 30000,
                    ],
                ],
                'underground_missile_base' => [
                    'name' => '地底ミサイル基地',
                    'effect' => [
                        'missile_launch_capacity' => 1,
                    ],
                ],
            ],
            'command_definitions' => [
                0 => [
                    'key' => 'build_underground_city',
                    'name' => '地底都市建設',
                    'description' => '空き地底施設枠へ地底都市を建設し、首都人口の成長上限を10,000人増やします。',
                    'target_type' => 'underground_slot',
                    'cost_money' => 1000,
                    'action' => 'build',
                    'facility_key' => 'underground_city',
                    'execution_phase' => 'underground_facility',
                    'sort_order' => 1,
                    'metadata' => [
                        'consumes_turn' => true,
                        'parameters' => [
                        ],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                1 => [
                    'key' => 'build_underground_farm',
                    'name' => '地底農場建設',
                    'description' => '空き地底施設枠へ地底農場を建設し、農場能力を10,000人分増やします。',
                    'target_type' => 'underground_slot',
                    'cost_money' => 1000,
                    'action' => 'build',
                    'facility_key' => 'underground_farm',
                    'execution_phase' => 'underground_facility',
                    'sort_order' => 2,
                    'metadata' => [
                        'consumes_turn' => true,
                        'parameters' => [
                        ],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                2 => [
                    'key' => 'build_underground_factory',
                    'name' => '地底工場建設',
                    'description' => '空き地底施設枠へ地底工場を建設し、工場能力を30,000人分増やします。',
                    'target_type' => 'underground_slot',
                    'cost_money' => 1000,
                    'action' => 'build',
                    'facility_key' => 'underground_factory',
                    'execution_phase' => 'underground_facility',
                    'sort_order' => 3,
                    'metadata' => [
                        'consumes_turn' => true,
                        'parameters' => [
                        ],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                3 => [
                    'key' => 'build_underground_missile_base',
                    'name' => '地底ミサイル基地建設',
                    'description' => '空き地底施設枠へ地底ミサイル基地を建設し、ミサイル1発分の発射能力を追加します。',
                    'target_type' => 'underground_slot',
                    'cost_money' => 1000,
                    'action' => 'build',
                    'facility_key' => 'underground_missile_base',
                    'execution_phase' => 'underground_facility',
                    'sort_order' => 4,
                    'metadata' => [
                        'consumes_turn' => true,
                        'parameters' => [
                        ],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                4 => [
                    'key' => 'remove_underground_facility',
                    'name' => '地下施設撤去',
                    'description' => '建築済みの地下施設を撤去して空き枠へ戻します。払い戻しはありません。',
                    'target_type' => 'underground_slot',
                    'cost_money' => 50,
                    'action' => 'remove',
                    'facility_key' => null,
                    'execution_phase' => 'underground_facility',
                    'sort_order' => 5,
                    'metadata' => [
                        'consumes_turn' => true,
                        'parameters' => [
                        ],
                        'quantity_semantics' => 'unused',
                    ],
                ],
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            0 => 'key',
            1 => 'target_type',
            2 => 'action',
            3 => 'facility_key',
            4 => 'execution_phase',
            5 => 'sort_order',
            6 => 'consumes_turn',
            7 => 'quantity_semantics',
        ],
        'data' => [
            0 => 'cost_money',
            1 => 'capital_maximum_population_bonus',
            2 => 'farm_capacity_people',
            3 => 'factory_capacity_people',
            4 => 'missile_launch_capacity',
        ],
        'flavor' => [
            0 => 'name',
            1 => 'description',
        ],
    ],
];
