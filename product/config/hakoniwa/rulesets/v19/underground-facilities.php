<?php

$behavior = [
    'key',
    'target_type',
    'action',
    'facility_key',
    'execution_phase',
    'sort_order',
    'consumes_turn',
    'quantity_semantics',
];

$data = [
    'cost_money',
    'capital_maximum_population_bonus',
    'farm_capacity_people',
    'factory_capacity_people',
    'missile_launch_capacity',
];

$flavor = ['name', 'description'];

return [
    'payload' => [
        'underground_facility_development' => [
            'facility_definitions' => [
                'underground_city' => [
                    'name' => '地底都市',
                    'effect' => ['capital_maximum_population_bonus' => 10_000],
                ],
                'underground_farm' => [
                    'name' => '地底農場',
                    'effect' => ['farm_capacity_people' => 10_000],
                ],
                'underground_factory' => [
                    'name' => '地底工場',
                    'effect' => ['factory_capacity_people' => 30_000],
                ],
                'underground_missile_base' => [
                    'name' => '地底ミサイル基地',
                    'effect' => ['missile_launch_capacity' => 1],
                ],
            ],
            'command_definitions' => [
                [
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
                        'parameters' => [],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                [
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
                        'parameters' => [],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                [
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
                        'parameters' => [],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                [
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
                        'parameters' => [],
                        'quantity_semantics' => 'unused',
                    ],
                ],
                [
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
                        'parameters' => [],
                        'quantity_semantics' => 'unused',
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
