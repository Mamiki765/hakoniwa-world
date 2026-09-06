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
            ],
        ],
    ],
    'classification' => [
        'behavior' => [],
        'data' => [
            'rank_one_maximum_scale',
            'rank_two_maximum_scale',
            'rank_two_scale_increment',
            '/facility_rank_system/definitions/farm/damage_scale_loss/ordinary_terrain_destruction',
            '/facility_rank_system/definitions/farm/damage_scale_loss/land_destruction',
            '/facility_rank_system/definitions/factory/damage_scale_loss/ordinary_terrain_destruction',
            '/facility_rank_system/definitions/factory/damage_scale_loss/land_destruction',
            '/facility_rank_system/definitions/factory/damage_scale_loss/fire',
            '/facility_rank_system/definitions/factory/damage_scale_loss/earthquake',
            '/facility_rank_system/definitions/mine/damage_scale_loss/ordinary_terrain_destruction',
            '/facility_rank_system/definitions/mine/damage_scale_loss/land_destruction',
            'typhoon_damage_threshold',
        ],
        'flavor' => [
            'rank_two_name',
            'rank_two_asset_key',
            'rank_two_effect_description',
            'promotion_description',
        ],
    ],
];
