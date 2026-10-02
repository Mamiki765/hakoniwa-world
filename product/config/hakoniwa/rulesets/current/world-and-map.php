<?php

return [
    'payload' => [
        'key' => 'hakoniwa-2s-plus-v27',
        'version' => 27,
        'chunk_size' => 16,
        'initial_x_min' => 0,
        'initial_x_max' => 59,
        'initial_y_min' => 0,
        'initial_y_max' => 59,
        'minimum_capital_distance' => 12,
        'capital_initial_population' => 1000,
        'capital_minimum_population' => 100,
        'initial_territory_radius' => 2,
        'initial_island_land_radius' => 2,
        'initial_island_growth_radius' => 4,
        'initial_island_reservation_radius' => 5,
        'initial_island_growth_steps' => 100,
        'initial_island_minimum_shallow_cells' => 3,
        'capital_relocation_cost_money' => 1000,
        'territory_transfer' => [
            'capital_core' => [
                'ownership_transfer_protected' => true,
                'owner_states' => [
                    0 => 'active',
                ],
                'radius' => 2,
            ],
        ],
        'initial_island_placement' => [
            'reservation_terrain_keys' => [
                0 => 'sea',
                1 => 'shallow',
                2 => 'wasteland',
                3 => 'mountain',
            ],
            'ship_relocation' => 'final_empty_sea_within_reservation',
            'candidate_evaluation' => 'stable_batched_until_safe',
        ],
    ],
    'classification' => [
        'behavior' => [
            0 => '/territory_transfer/capital_core/owner_states/*',
            1 => 'key',
            2 => 'ownership_transfer_protected',
            3 => 'version',
            4 => '/initial_island_placement/reservation_terrain_keys/*',
            5 => '/initial_island_placement/ship_relocation',
            6 => '/initial_island_placement/candidate_evaluation',
        ],
        'data' => [
            0 => 'capital_initial_population',
            1 => 'capital_minimum_population',
            2 => 'capital_relocation_cost_money',
            3 => 'chunk_size',
            4 => 'initial_island_growth_radius',
            5 => 'initial_island_growth_steps',
            6 => 'initial_island_land_radius',
            7 => 'initial_island_minimum_shallow_cells',
            8 => 'initial_island_reservation_radius',
            9 => 'initial_territory_radius',
            10 => 'initial_x_max',
            11 => 'initial_x_min',
            12 => 'initial_y_max',
            13 => 'initial_y_min',
            14 => 'minimum_capital_distance',
            15 => 'radius',
        ],
        'flavor' => [
        ],
    ],
];
