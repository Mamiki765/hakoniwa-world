<?php

return [
    'payload' => [
        'facility_rank_system' => [
            'definitions' => [
                'farm' => [
                    'rank_one_maximum_scale' => 50,
                    'rank_two_maximum_scale' => 100,
                    'rank_two_scale_increment' => 1,
                    'rank_two_name' => '大農場',
                    'rank_two_asset_key' => 'tile.large_farm',
                    'rank_two_effect_description' => '森3個分の台風耐性',
                    'promotion_description' => '50,000人規模で再度農場整備するとランク2へ',
                    'damage_scale_loss' => [
                        'ordinary_terrain_destruction' => 1,
                        'land_destruction' => 3,
                    ],
                    'typhoon_damage_threshold' => 3,
                ],
                'factory' => [
                    'rank_one_maximum_scale' => 100,
                    'rank_two_maximum_scale' => 200,
                    'rank_two_scale_increment' => 5,
                    'rank_two_name' => '大工場',
                    'rank_two_asset_key' => 'tile.large_factory',
                    'rank_two_effect_description' => 'ある程度の火事・地震耐性',
                    'promotion_description' => '100,000人規模で再度工場整備するとランク2へ',
                    'damage_scale_loss' => [
                        'ordinary_terrain_destruction' => 5,
                        'land_destruction' => 15,
                        'fire' => 20,
                        'earthquake' => 20,
                    ],
                ],
                'mine' => [
                    'rank_one_maximum_scale' => 200,
                    'rank_two_maximum_scale' => 400,
                    'rank_two_scale_increment' => 2,
                    'rank_two_name' => '大採掘場',
                    'rank_two_asset_key' => 'tile.large_mine',
                    'rank_two_effect_description' => '陸地破壊時の被害を規模減少に軽減',
                    'promotion_description' => '200,000人規模で再度採掘場整備するとランク2へ',
                    'damage_scale_loss' => [
                        'ordinary_terrain_destruction' => 0,
                        'land_destruction' => 2,
                    ],
                ],
                'city' => [
                    'rank_one_maximum_population' => 20000,
                    'rank_two_name' => '大都市',
                    'rank_two_asset_key' => 'tile.large_city',
                    'rank_two_effect_description' => '地震・火災を受けず、通常怪獣の自然発生先にならない。',
                    'promotion_description' => '人口20,001人以上で自動的にランク2、20,000人以下で通常都市へ',
                    'immune_disaster_keys' => [
                        0 => 'earthquake',
                        1 => 'fire',
                    ],
                    'exclude_normal_monster_spawn' => true,
                    'population_damage_reference' => 'farm',
                    'population_per_damage_unit' => 1000,
                    'minimum_population' => 0,
                    'capital_population_loss_policy' => 'minimum_of_existing_and_fixed_loss',
                ],
            ],
        ],
    ],
    'classification' => [
        'behavior' => [
            0 => '/facility_rank_system/definitions/city/immune_disaster_keys/*',
            1 => 'exclude_normal_monster_spawn',
            2 => 'population_damage_reference',
            3 => 'capital_population_loss_policy',
        ],
        'data' => [
            0 => 'rank_one_maximum_scale',
            1 => 'rank_two_maximum_scale',
            2 => 'rank_two_scale_increment',
            3 => '/facility_rank_system/definitions/farm/damage_scale_loss/ordinary_terrain_destruction',
            4 => '/facility_rank_system/definitions/farm/damage_scale_loss/land_destruction',
            5 => '/facility_rank_system/definitions/factory/damage_scale_loss/ordinary_terrain_destruction',
            6 => '/facility_rank_system/definitions/factory/damage_scale_loss/land_destruction',
            7 => '/facility_rank_system/definitions/factory/damage_scale_loss/fire',
            8 => '/facility_rank_system/definitions/factory/damage_scale_loss/earthquake',
            9 => '/facility_rank_system/definitions/mine/damage_scale_loss/ordinary_terrain_destruction',
            10 => '/facility_rank_system/definitions/mine/damage_scale_loss/land_destruction',
            11 => 'typhoon_damage_threshold',
            12 => 'rank_one_maximum_population',
            13 => 'population_per_damage_unit',
            14 => 'minimum_population',
        ],
        'flavor' => [
            0 => 'rank_two_name',
            1 => 'rank_two_asset_key',
            2 => 'rank_two_effect_description',
            3 => 'promotion_description',
        ],
    ],
];
