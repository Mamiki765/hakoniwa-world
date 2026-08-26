<?php

$domain = require __DIR__.'/../current/turn-pipeline.php';
$payload = $domain['payload'];
$payload['turn_processing']['settlement']['over_attraction_maximum_decline'] = [
    'facility_keys' => ['village', 'town', 'city'],
    'excluded_facility_key' => 'capital',
    'loss_per_turn' => 100,
    'skip_natural_growth' => true,
    'event_type' => 'population.decreased',
    'reason' => 'above_attraction_maximum',
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/turn_processing/settlement/over_attraction_maximum_decline/facility_keys/*',
    'excluded_facility_key', 'skip_natural_growth', 'event_type', 'reason',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'loss_per_turn',
]));

return ['payload' => $payload, 'classification' => $classification];
