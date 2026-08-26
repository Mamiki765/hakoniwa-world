<?php

$domain = require __DIR__.'/../v17/monsters-and-military.php';
$payload = $domain['payload'];
$payload['military']['refugees']['excluded_facility_keys'] = ['undersea_city'];
$payload['military']['seabed_base_resistance'] = [
    'facility_keys' => ['seabed_base', 'undersea_city'],
    'ineffective_missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
    'destructive_missile_keys' => ['land_destruction_missile'],
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/military/refugees/excluded_facility_keys/*',
    '/military/seabed_base_resistance/facility_keys/*',
]));

return ['payload' => $payload, 'classification' => $classification];
