<?php

$domain = require __DIR__.'/../v18/turn-pipeline.php';
$payload = $domain['payload'];
$classification = $domain['classification'];

$payload['turn_processing']['territory_influence']['acquisition_land_limit'] = 'land_subsidence_safe_land_cells';
$classification['behavior'][] = '/turn_processing/territory_influence/acquisition_land_limit';

return ['payload' => $payload, 'classification' => $classification];
