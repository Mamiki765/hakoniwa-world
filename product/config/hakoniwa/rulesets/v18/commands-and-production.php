<?php

$domain = require __DIR__.'/../current/commands-and-production.php';
$payload = $domain['payload'];
$payload['command_definitions'][] = [
    'key' => 'build_undersea_city',
    'name' => '海底都市建設',
    'description' => '首都から3,000人を移住させ、自国領から3hex以内の海へ秘密の海底都市を建設します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['sea'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 1000,
    'required_resources' => [],
    'execution_phase' => 'facility',
    'result_terrain_key' => 'sea',
    'result_facility_key' => 'undersea_city',
    'sort_order' => 260,
    'metadata' => [
        'consumes_turn' => true,
        'parameters' => [],
        'minimum_capital_population' => 3100,
        'capital_transfer_population' => 3000,
    ],
];

$classification = $domain['classification'];
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'minimum_capital_population',
    'capital_transfer_population',
]));

return ['payload' => $payload, 'classification' => $classification];
