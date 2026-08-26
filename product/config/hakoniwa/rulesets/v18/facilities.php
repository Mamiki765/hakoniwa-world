<?php

$domain = require __DIR__.'/../current/facilities.php';
$payload = $domain['payload'];
$payload['facility_definitions']['undersea_city'] = [
    'disguise_ownership_policy' => 'neutral',
    'scale_unit_people' => null,
    'initial_scale' => null,
    'scale_increment' => null,
    'maximum_scale' => null,
    'workforce_per_scale_people' => null,
    'production_definition_key' => null,
    'name' => '海底都市',
    'asset_key' => 'tile.undersea_city',
    'visibility_policy' => 'disguised',
    'disguise_terrain_key' => 'sea',
    'disguise_asset_key' => 'tile.sea',
    'build_command_key' => 'build_undersea_city',
    'buildable_terrain_keys' => ['sea'],
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/facility_definitions/undersea_city/buildable_terrain_keys/*',
    '/facility_definitions/undersea_city/scale_unit_people',
    '/facility_definitions/undersea_city/initial_scale',
    '/facility_definitions/undersea_city/scale_increment',
    '/facility_definitions/undersea_city/maximum_scale',
    '/facility_definitions/undersea_city/workforce_per_scale_people',
]));

return ['payload' => $payload, 'classification' => $classification];
