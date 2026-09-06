<?php

$domain = require __DIR__.'/../v18/lifecycle-and-karma.php';
$payload = $domain['payload'];
$payload['karma']['impact_points']['facility_scale_damaged'] = 1;
$payload['karma']['impact_points']['facility_scale_land_damaged'] = 3;

$classification = $domain['classification'];
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'facility_scale_damaged',
    'facility_scale_land_damaged',
]));

return ['payload' => $payload, 'classification' => $classification];
