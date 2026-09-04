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

return ['payload' => $payload, 'classification' => $domain['classification']];
