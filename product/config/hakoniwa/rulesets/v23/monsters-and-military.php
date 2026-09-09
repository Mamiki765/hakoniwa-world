<?php

$domain = require __DIR__.'/../v21/monsters-and-military.php';
$payload = $domain['payload'];

$blockedCentralFacilities = ['central_bank', 'central_granary'];
foreach ($payload['monster_definitions'] as &$definition) {
    $definition['movement_terrain_contract']['blocked_facility_keys'] = array_values(array_unique([
        ...$definition['movement_terrain_contract']['blocked_facility_keys'],
        ...$blockedCentralFacilities,
    ]));
}
unset($definition);
$payload['monster_system']['movement']['blocked_facility_keys'] = array_values(array_unique([
    ...$payload['monster_system']['movement']['blocked_facility_keys'],
    ...$blockedCentralFacilities,
]));

return ['payload' => $payload, 'classification' => $domain['classification']];
