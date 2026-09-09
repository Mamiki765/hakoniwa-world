<?php

$domain = require __DIR__.'/../v20/commands-and-production.php';
$payload = $domain['payload'];
$classification = $domain['classification'];

$payload['command_definitions'][] = [
    'key' => 'build_fast_farm',
    'name' => '高速農場建設',
    'description' => '20 Pdを使い、turnを消費せず平地へ農場を建設または整備します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['plain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 100,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'plain',
    'result_facility_key' => 'farm',
    'sort_order' => 55,
    'metadata' => [
        'initial_scale_from_facility_definition' => true,
        'future_expand_command' => 'expand_farm',
        'execution_deferred' => false,
        'consumes_turn' => false,
        'cost_paradox' => 20,
        'command_group' => 'paradox',
    ],
];

$payload['command_definitions'][] = [
    'key' => 'build_fast_factory',
    'name' => '高速工場建設',
    'description' => '20 Pdを使い、turnを消費せず平地へ工場を建設または整備します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['plain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 300,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'plain',
    'result_facility_key' => 'factory',
    'sort_order' => 65,
    'metadata' => [
        'initial_scale_from_facility_definition' => true,
        'future_expand_command' => 'expand_factory',
        'execution_deferred' => false,
        'consumes_turn' => false,
        'cost_paradox' => 20,
        'command_group' => 'paradox',
    ],
];

$payload['command_definitions'][] = [
    'key' => 'build_fast_mine',
    'name' => '高速採掘場建設',
    'description' => '20 Pdを使い、turnを消費せず山へ採掘場を建設または整備します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['mountain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 1000,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'mountain',
    'result_facility_key' => 'mine',
    'sort_order' => 75,
    'metadata' => [
        'initial_scale_from_facility_definition' => true,
        'future_expand_command' => 'expand_mine',
        'execution_deferred' => false,
        'consumes_turn' => false,
        'cost_paradox' => 20,
        'command_group' => 'paradox',
    ],
];

$payload['command_definitions'][] = [
    'key' => 'build_central_bank',
    'name' => '中央銀行建設',
    'description' => '平地へ中央銀行を建設します。既存の中央銀行ではLvが1上がります。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['plain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 9999,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'plain',
    'result_facility_key' => 'central_bank',
    'sort_order' => 115,
    'metadata' => [
        'initial_scale_from_facility_definition' => true,
        'execution_deferred' => false,
        'consumes_turn' => true,
    ],
];

$payload['command_definitions'][] = [
    'key' => 'build_central_granary',
    'name' => '中央穀倉建設',
    'description' => '平地へ中央穀倉を建設します。既存の中央穀倉ではLvが1上がります。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['plain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 9999,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'plain',
    'result_facility_key' => 'central_granary',
    'sort_order' => 117,
    'metadata' => [
        'initial_scale_from_facility_definition' => true,
        'execution_deferred' => false,
        'consumes_turn' => true,
    ],
];

$classification['behavior'][] = 'command_group';
$classification['data'][] = 'cost_paradox';

return ['payload' => $payload, 'classification' => $classification];
