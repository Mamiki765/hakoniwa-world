<?php

use App\Domain\Command\DevelopmentPlanQuantity;

$roadmapPr2Ruleset = [
    'key' => 'roadmap-pr2-v1',
    'version' => 3,
    'chunk_size' => 16,
    'initial_x_min' => 0,
    'initial_x_max' => 59,
    'initial_y_min' => 0,
    'initial_y_max' => 59,
    'minimum_capital_distance' => 12,
    'capital_initial_population' => 1000,
    'capital_minimum_population' => 1,
    'initial_money' => 100,
    'resource_definitions' => [
        ['key' => 'wheat', 'name' => '小麦', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 1, 'storable' => true, 'tradable' => true, 'sale_price_key' => 'sale.wheat', 'sort_order' => 10, 'metadata' => ['produced_by' => 'farm']],
        ['key' => 'fish', 'name' => '魚', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 1, 'storable' => true, 'tradable' => true, 'sale_price_key' => 'sale.fish', 'sort_order' => 20, 'metadata' => []],
        ['key' => 'monster_meat', 'name' => '肉', 'category' => 'food', 'unit' => 'unit', 'nutrition_per_unit' => 2, 'storable' => true, 'tradable' => true, 'sale_price_key' => 'sale.monster_meat', 'sort_order' => 30, 'metadata' => ['nutrition_is_provisional' => true]],
        ['key' => 'industrial_goods', 'name' => '工業品', 'category' => 'industry', 'unit' => 'unit', 'nutrition_per_unit' => null, 'storable' => true, 'tradable' => true, 'sale_price_key' => 'sale.industrial_goods', 'sort_order' => 40, 'metadata' => ['produced_by' => 'factory']],
        ['key' => 'minerals', 'name' => '鉱物', 'category' => 'material', 'unit' => 'unit', 'nutrition_per_unit' => null, 'storable' => true, 'tradable' => true, 'sale_price_key' => 'sale.minerals', 'sort_order' => 50, 'metadata' => ['produced_by' => 'mine']],
    ],
    'resource_sale_prices' => [
        'sale.wheat' => 1,
        'sale.fish' => 1,
        'sale.monster_meat' => 2,
        'sale.industrial_goods' => 1,
        'sale.minerals' => 1,
    ],
    'initial_resources' => ['wheat' => 100, 'fish' => 0, 'monster_meat' => 0, 'industrial_goods' => 0, 'minerals' => 0],
    'default_sale_policy' => 'stockpile',
    'command_queue_limit' => 20,
    'terrain_quantities' => [
        'forest' => [
            'key' => 'trees',
            'label' => '木',
            'unit' => '本',
            'initial_quantity' => 500,
            'minimum_quantity' => 0,
            'maximum_quantity' => 20000,
            'growth_increment' => 100,
            'growth_rule_key' => 'legacy.forest.grow_each_turn',
        ],
    ],
    'facility_definitions' => [
        'village' => [
            'name' => '村', 'asset_key' => 'tile.village', 'visibility_policy' => 'public',
            'build_command_key' => null, 'scale_unit_people' => null, 'initial_scale' => null,
            'scale_increment' => null, 'maximum_scale' => null, 'workforce_per_scale_people' => null,
            'production_definition_key' => null, 'buildable_terrain_keys' => [],
        ],
        'capital' => [
            'name' => '首都', 'asset_key' => 'tile.capital', 'visibility_policy' => 'public',
            'build_command_key' => null, 'scale_unit_people' => null, 'initial_scale' => null,
            'scale_increment' => null, 'maximum_scale' => null, 'workforce_per_scale_people' => null,
            'production_definition_key' => null, 'buildable_terrain_keys' => [],
        ],
        'farm' => [
            'name' => '農場', 'asset_key' => 'tile.farm', 'visibility_policy' => 'public',
            'build_command_key' => 'build_farm', 'scale_unit_people' => 1000, 'initial_scale' => 10,
            'scale_increment' => 2, 'maximum_scale' => 50, 'workforce_per_scale_people' => 1000,
            'production_definition_key' => 'farm_wheat', 'buildable_terrain_keys' => ['plain'],
        ],
        'factory' => [
            'name' => '工場', 'asset_key' => 'tile.factory', 'visibility_policy' => 'public',
            'build_command_key' => 'build_factory', 'scale_unit_people' => 1000, 'initial_scale' => 30,
            'scale_increment' => 10, 'maximum_scale' => 100, 'workforce_per_scale_people' => 1000,
            'production_definition_key' => 'factory_industrial_goods', 'buildable_terrain_keys' => ['plain'],
        ],
        'mine' => [
            'name' => '採掘場', 'asset_key' => 'tile.mine', 'visibility_policy' => 'public',
            'build_command_key' => 'build_mine', 'scale_unit_people' => 1000, 'initial_scale' => 5,
            'scale_increment' => 5, 'maximum_scale' => 200, 'workforce_per_scale_people' => 1000,
            'production_definition_key' => 'mine_minerals', 'buildable_terrain_keys' => ['mountain'],
        ],
        'missile_base' => [
            'name' => 'ミサイル基地', 'asset_key' => 'tile.missile_base', 'visibility_policy' => 'disguised',
            'disguise_terrain_key' => 'forest', 'disguise_asset_key' => 'tile.forest',
            'build_command_key' => null, 'scale_unit_people' => null, 'initial_scale' => null,
            'scale_increment' => null, 'maximum_scale' => null, 'workforce_per_scale_people' => null,
            'production_definition_key' => null, 'buildable_terrain_keys' => ['plain'],
            'initial_experience' => 0, 'maximum_experience' => 200,
            'level_thresholds' => [20, 60, 120, 200],
            'launch_capacity_by_level' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
        ],
    ],
    'command_definitions' => [
        ['key' => 'land_clear', 'name' => '整地', 'description' => '所有する陸地を平地にします。', 'target_type' => 'cell', 'target_terrain_keys' => ['wasteland', 'plain', 'forest'], 'target_facility_keys' => [], 'requires_empty_facility' => false, 'cost_money' => 5, 'required_resources' => [], 'execution_phase' => 'terrain', 'result_terrain_key' => 'plain', 'result_facility_key' => null, 'sort_order' => 10, 'metadata' => ['legacy_command' => 'Prepare', 'consumes_turn' => true, 'execution_deferred' => true]],
        ['key' => 'land_level', 'name' => '地ならし', 'description' => '所有する陸地を平地にします。旧作ではターンを消費せず地震判定があります。', 'target_type' => 'cell', 'target_terrain_keys' => ['wasteland', 'plain', 'forest'], 'target_facility_keys' => [], 'requires_empty_facility' => false, 'cost_money' => 100, 'required_resources' => [], 'execution_phase' => 'terrain', 'result_terrain_key' => 'plain', 'result_facility_key' => null, 'sort_order' => 20, 'metadata' => ['legacy_command' => 'Prepare2', 'consumes_turn' => false, 'earthquake_check_deferred' => true, 'execution_deferred' => true]],
        ['key' => 'reclaim', 'name' => '埋め立て', 'description' => '海を浅瀬へ、浅瀬を荒地へ近づけます。', 'target_type' => 'cell', 'target_terrain_keys' => ['sea', 'shallow'], 'target_facility_keys' => [], 'requires_empty_facility' => true, 'cost_money' => 150, 'required_resources' => [], 'execution_phase' => 'terrain', 'result_terrain_key' => null, 'result_facility_key' => null, 'sort_order' => 30, 'metadata' => ['legacy_command' => 'Reclaim', 'result_depends_on_target' => true, 'neighbor_validation_at_execution' => true, 'execution_deferred' => true]],
        ['key' => 'excavate', 'name' => '掘削', 'description' => '陸地を浅瀬へ、浅瀬を海へ、山を荒地へ変更します。', 'target_type' => 'cell', 'target_terrain_keys' => ['sea', 'shallow', 'wasteland', 'plain', 'forest', 'mountain'], 'target_facility_keys' => [], 'requires_empty_facility' => false, 'cost_money' => 200, 'required_resources' => [], 'execution_phase' => 'terrain', 'result_terrain_key' => null, 'result_facility_key' => null, 'sort_order' => 40, 'metadata' => ['legacy_command' => 'Destroy', 'result_depends_on_target' => true, 'oil_search_deferred' => true, 'execution_deferred' => true, 'parameters' => ['quantity' => ['label' => '数量', 'type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1, 'quick_presets' => [1, 5, 10, 25, 50, 99], 'required' => true, 'meaning' => 'turn engineで実行する掘削回数。PR #5では予約・表示・検証だけを行う。']]]],
        ['key' => 'build_farm', 'name' => '農場建設', 'description' => '平地へ農場を建設します。初期規模はrulesetを参照します。', 'target_type' => 'cell', 'target_terrain_keys' => ['plain'], 'target_facility_keys' => [], 'requires_empty_facility' => true, 'cost_money' => 20, 'required_resources' => [], 'execution_phase' => 'facility', 'result_terrain_key' => 'plain', 'result_facility_key' => 'farm', 'sort_order' => 50, 'metadata' => ['legacy_command' => 'Farm', 'initial_scale_from_facility_definition' => true, 'future_expand_command' => 'expand_farm', 'execution_deferred' => true]],
        ['key' => 'build_factory', 'name' => '工場建設', 'description' => '平地へ工場を建設します。初期規模はrulesetを参照します。', 'target_type' => 'cell', 'target_terrain_keys' => ['plain'], 'target_facility_keys' => [], 'requires_empty_facility' => true, 'cost_money' => 100, 'required_resources' => [], 'execution_phase' => 'facility', 'result_terrain_key' => 'plain', 'result_facility_key' => 'factory', 'sort_order' => 60, 'metadata' => ['legacy_command' => 'Factory', 'initial_scale_from_facility_definition' => true, 'future_expand_command' => 'expand_factory', 'execution_deferred' => true]],
        ['key' => 'build_mine', 'name' => '採掘場建設', 'description' => '山へ採掘場を建設します。初期規模はrulesetを参照します。', 'target_type' => 'cell', 'target_terrain_keys' => ['mountain'], 'target_facility_keys' => [], 'requires_empty_facility' => true, 'cost_money' => 300, 'required_resources' => [], 'execution_phase' => 'facility', 'result_terrain_key' => 'mountain', 'result_facility_key' => 'mine', 'sort_order' => 70, 'metadata' => ['legacy_command' => 'Mountain', 'initial_scale_from_facility_definition' => true, 'future_expand_command' => 'expand_mine', 'execution_deferred' => true]],
    ],
    'production_definitions' => [
        ['key' => 'farm_wheat', 'facility_key' => 'farm', 'output_resource_key' => 'wheat', 'production_per_scale' => 10, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_runner_workforce_allocation', 'price_reference' => 'sale.wheat', 'metadata' => ['legacy_formula' => 'food += farm_scale * 10']],
        ['key' => 'factory_industrial_goods', 'facility_key' => 'factory', 'output_resource_key' => 'industrial_goods', 'production_per_scale' => 1, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_runner_workforce_allocation', 'price_reference' => 'sale.industrial_goods', 'metadata' => ['new_output_mapping_is_provisional' => true, 'legacy_capacity_contributes_to_money' => true]],
        ['key' => 'mine_minerals', 'facility_key' => 'mine', 'output_resource_key' => 'minerals', 'production_per_scale' => 1, 'required_workforce_per_scale' => 1000, 'operating_condition' => 'turn_runner_workforce_allocation', 'price_reference' => 'sale.minerals', 'metadata' => ['new_output_mapping_is_provisional' => true, 'legacy_capacity_contributes_to_money' => true]],
    ],
    'initial_territory_radius' => 2,
    'initial_island_land_radius' => 2,
    'initial_island_growth_radius' => 4,
    'initial_island_reservation_radius' => 5,
    'initial_island_growth_steps' => 100,
];

$roadmapPr6Ruleset = $roadmapPr2Ruleset;
$roadmapPr6Ruleset['key'] = 'roadmap-pr6-v1';
$roadmapPr6Ruleset['version'] = 1;
$roadmapPr6Ruleset['development_plan_quantity'] = DevelopmentPlanQuantity::contract();
$roadmapPr6Ruleset['initial_resources'] = [
    'wheat' => 10_000,
    'fish' => 0,
    'monster_meat' => 0,
    'industrial_goods' => 0,
    'minerals' => 0,
];
$roadmapPr6Ruleset['initial_island_minimum_shallow_cells'] = 3;

foreach ($roadmapPr6Ruleset['resource_definitions'] as &$resourceDefinition) {
    $resourceDefinition['unit_label'] = null;
    if ($resourceDefinition['category'] === 'food') {
        $resourceDefinition['unit'] = 'ton';
        $resourceDefinition['unit_label'] = 'トン';
    }
    if ($resourceDefinition['key'] === 'monster_meat') {
        $resourceDefinition['name'] = '怪獣肉';
    }
}
unset($resourceDefinition);

foreach ($roadmapPr6Ruleset['command_definitions'] as &$commandDefinition) {
    if (isset($commandDefinition['metadata']['parameters']['quantity'])) {
        unset($commandDefinition['metadata']['parameters']['quantity']);
        if ($commandDefinition['metadata']['parameters'] === []) {
            unset($commandDefinition['metadata']['parameters']);
        }
    }
}
unset($commandDefinition);

$roadmapPr7Ruleset = $roadmapPr6Ruleset;
$roadmapPr7Ruleset['key'] = 'roadmap-pr7-v1';
$roadmapPr7Ruleset['version'] = 1;
$roadmapPr7Ruleset['base_money_capacity'] = 9_999;
$roadmapPr7Ruleset['base_food_capacity_tons'] = 999_900;
$roadmapPr7Ruleset['inventory_sale_rates'] = [
    'industrial_goods' => ['inventory_units' => 1_000, 'money_units' => 1],
    'minerals' => ['inventory_units' => 1_000, 'money_units' => 1],
];

return [
    'ruleset' => $roadmapPr7Ruleset,
    'published_rulesets' => [
        'roadmap-pr2-v1' => $roadmapPr2Ruleset,
        'roadmap-pr6-v1' => $roadmapPr6Ruleset,
        'roadmap-pr7-v1' => $roadmapPr7Ruleset,
    ],
    'world' => [
        'key' => 'shared-world',
        'name' => '共有世界',
        'map_space_key' => 'surface',
        'map_space_name' => '地上',
        'generator_id' => 'ocean-world',
        'generator_version' => '3',
        'seed' => 'hakoniwa-staggered-xy-v3',
    ],
    'initial_island' => [
        'generator_id' => 'legacy-inspired-initial-island',
        'generator_version' => '3',
    ],
    'assets' => [
        'base_url' => env('HAKONIWA_TILE_ASSET_BASE_URL', env('HAKONIWA_ORIGINAL_ASSET_BASE_URL', '/assets/hakoniwa-tiles')),
        'path' => env('HAKONIWA_TILE_ASSET_PATH', env('HAKONIWA_ORIGINAL_ASSET_PATH', '/srv/hakoniwa-assets/tiles')),
        'allowed_extensions' => ['gif', 'png', 'webp'],
    ],
];
