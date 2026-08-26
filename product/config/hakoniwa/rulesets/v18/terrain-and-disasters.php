<?php

$domain = require __DIR__.'/../current/terrain-and-disasters.php';
$payload = $domain['payload'];
$disasters = &$payload['turn_processing']['disasters'];
$disasters['tsunami']['excluded_facility_keys'][] = 'undersea_city';
$disasters['tsunami']['water_facility_keys'][] = 'undersea_city';
foreach (['meteor_shower', 'huge_meteor', 'eruption'] as $key) {
    $disasters[$key]['seabed_facility_keys'][] = 'undersea_city';
}
$disasters['fire']['unprotected_sea_facility_keys'] = ['undersea_city'];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/turn_processing/disasters/fire/unprotected_sea_facility_keys/*',
]));

return ['payload' => $payload, 'classification' => $classification];
