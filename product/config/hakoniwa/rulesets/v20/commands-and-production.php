<?php

$domain = require __DIR__.'/../v19/commands-and-production.php';
$payload = $domain['payload'];

$payload['command_definitions'][] = [
    'key' => 'build_port',
    'name' => '港建設',
    'description' => '自国陸地と深海に隣接する中立の浅瀬を埋め立て、港を建設します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['shallow'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 1000,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'plain',
    'result_facility_key' => 'port',
    'sort_order' => 135,
    'metadata' => [
        'consumes_turn' => true,
        'parameters' => [],
    ],
];

$payload['command_definitions'][] = [
    'key' => 'build_ship',
    'name' => '船建造',
    'description' => '船種を選び、自国の港から2hex以内にある航行可能な深海へ船を建造します。',
    'target_type' => 'nation',
    'target_terrain_keys' => ['sea', 'shallow', 'wasteland', 'scorched', 'plain', 'forest', 'mountain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => false,
    'cost_money' => 500,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => null,
    'result_facility_key' => null,
    'sort_order' => 137,
    'metadata' => [
        'consumes_turn' => true,
        'parameters' => [],
        'quantity_selects_catalog' => 'surface_ship_definitions',
        'default_selector_value' => 1,
    ],
];

$payload['command_definitions'][] = [
    'key' => 'scuttle_ship',
    'name' => '廃船',
    'description' => '選択した自国の船を、返金なしで廃船にします。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['sea'],
    'target_facility_keys' => [],
    'requires_empty_facility' => false,
    'cost_money' => 0,
    'required_resources' => [],
    'execution_phase' => 'operations',
    'result_terrain_key' => null,
    'result_facility_key' => null,
    'sort_order' => 138,
    'metadata' => [
        'consumes_turn' => true,
        'parameters' => [],
    ],
];

return ['payload' => $payload, 'classification' => $domain['classification']];
