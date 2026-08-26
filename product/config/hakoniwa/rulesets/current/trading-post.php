<?php

$behavior = [
    0 => '/trading_post/npc/resource_keys/*',
    1 => 'fee_behavior',
    2 => 'item_rarity',
    3 => 'random_stream_version',
    4 => 'seller_key',
    5 => 'seller_proceeds_rounding',
];

$data = [
    0 => 'active_item_limit',
    1 => 'active_listing_limit',
    2 => 'active_resource_limit',
    3 => 'attempts_per_turn',
    4 => 'denominator',
    5 => 'duration_turns',
    6 => 'item_price_money_per_level',
    7 => 'maximum',
    8 => 'maximum_duration_turns',
    9 => 'minimum',
    10 => 'minimum_duration_turns',
    11 => 'minimum_increment_money',
    12 => 'numerator',
    13 => 'seller_proceeds_denominator',
    14 => 'seller_proceeds_numerator',
];

$flavor = [
    0 => 'seller_name',
];

return [
    'payload' => [
        'trading_post' => [
            'player' => [
                'active_listing_limit' => 3,
                'minimum_duration_turns' => 3,
                'maximum_duration_turns' => 84,
                'minimum_increment_money' => 1,
                'seller_proceeds_numerator' => 9,
                'seller_proceeds_denominator' => 10,
                'seller_proceeds_rounding' => 'floor',
                'fee_behavior' => 'discard_remainder_on_sale',
            ],
            'npc' => [
                'seller_key' => 'hakoniwa_federation',
                'seller_name' => '箱庭連合',
                'duration_turns' => 6,
                'attempts_per_turn' => 3,
                'listing_probability' => [
                    'numerator' => 40,
                    'denominator' => 100,
                ],
                'active_resource_limit' => 3,
                'active_item_limit' => 2,
                'resource_keys' => [
                    0 => 'wheat',
                    1 => 'fish',
                    2 => 'monster_meat',
                    3 => 'industrial_goods',
                    4 => 'minerals',
                    5 => 'oil',
                ],
                'resource_base_value_money' => [
                    'minimum' => 100,
                    'maximum' => 1000,
                ],
                'resource_start_price_percent' => [
                    'minimum' => 100,
                    'maximum' => 130,
                ],
                'item_rarity' => 'novice',
                'item_level' => [
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'item_price_money_per_level' => 100,
                'random_stream_version' => 1,
            ],
        ],
    ],
    'classification' => [
        'behavior' => $behavior,
        'data' => $data,
        'flavor' => $flavor,
    ],
];
