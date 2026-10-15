<?php

return [
    'payload' => [
        'ecopo' => [
            'behavior' => 'credit_final_capacity_discard',
            'exchange_stage' => 'finalize_turn_before_lifecycle',
            'excluded_resource_keys' => ['power'],
            'points_per_money' => 1000,
            'points_per_paradox' => 10000000,
            'name' => 'エコポ',
            'unit' => '点',
        ],
    ],
    'classification' => [
        'behavior' => ['behavior', 'exchange_stage', '/ecopo/excluded_resource_keys/*'],
        'data' => ['points_per_money', 'points_per_paradox'],
        'flavor' => ['name', 'unit'],
    ],
];
