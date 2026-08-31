<?php

$domain = require __DIR__.'/../v18/commands-and-production.php';
$payload = $domain['payload'];

foreach ($payload['command_definitions'] as &$definition) {
    if ($definition['key'] === 'build_undersea_city') {
        $definition['sort_order'] = 125;
        break;
    }
}
unset($definition);

$payload['command_definitions'][] = [
    'key' => 'territory_abandon',
    'name' => '領土破棄',
    'description' => '自国領の海・浅瀬・荒地または人口と施設のない平地を、ターンを消費せず無主地へ戻します。',
    'target_type' => 'cell',
    'target_terrain_keys' => ['sea', 'shallow', 'wasteland', 'plain'],
    'target_facility_keys' => [],
    'requires_empty_facility' => true,
    'cost_money' => 0,
    'required_resources' => [],
    'execution_phase' => 'terrain',
    'result_terrain_key' => null,
    'result_facility_key' => null,
    'sort_order' => 95,
    'metadata' => [
        'consumes_turn' => false,
        'parameters' => [],
    ],
];

return ['payload' => $payload, 'classification' => $domain['classification']];
