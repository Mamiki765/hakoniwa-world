<?php

$domain = require __DIR__.'/../v17/turn-pipeline.php';
$payload = $domain['payload'];
$payload['turn_processing']['settlement']['population_facility_keys'] = [
    'village', 'town', 'city', 'capital', 'undersea_city',
];
$payload['turn_processing']['settlement']['fixed_identity_facility_keys'] = [
    'capital', 'undersea_city',
];
$payload['turn_processing']['settlement']['over_attraction_maximum_decline']['facility_keys'][] = 'undersea_city';
$payload['turn_processing']['undersea_city_maintenance'] = [
    'facility_key' => 'undersea_city',
    'resource_keys' => ['industrial_goods', 'minerals'],
    'base_units_per_resource' => 1000,
    'substitution_units_per_shortage' => 2,
    'minimum_population' => 3000,
    'payment_policy' => 'all_or_nothing',
    'settlement_order' => 'map_cell_id_ascending',
    'failure_population_loss' => 'canonical_famine_once_per_cell',
    'after' => 'resource_production',
    'before' => 'resource_sales',
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/turn_processing/settlement/population_facility_keys/*',
    '/turn_processing/settlement/fixed_identity_facility_keys/*',
    '/turn_processing/undersea_city_maintenance/resource_keys/*',
    'payment_policy', 'settlement_order', 'failure_population_loss',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'base_units_per_resource', 'substitution_units_per_shortage',
]));

return ['payload' => $payload, 'classification' => $classification];
