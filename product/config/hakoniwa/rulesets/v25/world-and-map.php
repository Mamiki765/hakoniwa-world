<?php

$domain = require __DIR__.'/../v24/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v25';
$payload['version'] = 25;
$payload['initial_island_placement']['candidate_evaluation'] = 'stable_batched_until_safe';
$classification = $domain['classification'];
$classification['behavior'][] = '/initial_island_placement/candidate_evaluation';

return [
    'payload' => $payload,
    'classification' => $classification,
];
