<?php

return [
    'ruleset' => [
        'key' => 'mvp-v1',
        'version' => 1,
        'chunk_size' => 16,
        'initial_q_min' => -30,
        'initial_q_max' => 29,
        'initial_r_min' => -30,
        'initial_r_max' => 29,
        'minimum_capital_distance' => 12,
        'capital_initial_population' => 1000,
        'capital_minimum_population' => 1,
        'initial_money' => 100,
        'resource_definitions' => [
            ['key' => 'wheat', 'name' => '小麦', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 1, 'storable' => true, 'tradable' => true, 'sale_price_key' => null, 'sort_order' => 10, 'metadata' => ['produced_by' => 'farm']],
            ['key' => 'fish', 'name' => '魚', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 1, 'storable' => true, 'tradable' => true, 'sale_price_key' => null, 'sort_order' => 20, 'metadata' => []],
            ['key' => 'monster_meat', 'name' => '肉', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 2, 'storable' => true, 'tradable' => true, 'sale_price_key' => null, 'sort_order' => 30, 'metadata' => ['nutrition_is_provisional' => true]],
        ],
        'resource_sale_prices' => [],
        'initial_resources' => ['wheat' => 100, 'fish' => 0, 'monster_meat' => 0],
        'initial_territory_radius' => 2,
        'initial_island_land_radius' => 2,
        'initial_island_growth_radius' => 4,
        'initial_island_reservation_radius' => 5,
        'initial_island_growth_steps' => 100,
    ],
    'world' => [
        'key' => 'shared-world',
        'name' => '共有世界',
        'map_space_key' => 'surface',
        'map_space_name' => '地上',
        'generator_id' => 'ocean-world',
        'generator_version' => '1',
        'seed' => 'hakoniwa-mvp-ocean-v1',
    ],
    'initial_island' => [
        'generator_id' => 'legacy-inspired-initial-island',
        'generator_version' => '1',
    ],
    'assets' => [
        'base_url' => env('HAKONIWA_ORIGINAL_ASSET_BASE_URL', '/assets/hakoniwa-original'),
        'path' => env('HAKONIWA_ORIGINAL_ASSET_PATH', '/srv/hakoniwa-assets/original'),
    ],
];
