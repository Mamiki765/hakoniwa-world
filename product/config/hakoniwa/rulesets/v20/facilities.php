<?php

$domain = require __DIR__.'/../v18/facilities.php';
$payload = $domain['payload'];
$classification = $domain['classification'];

$payload['facility_definitions']['port'] = [
    'name' => '港',
    'asset_key' => 'tile.port',
    'visibility_policy' => 'public',
    'build_command_key' => 'build_port',
    'scale_unit_people' => null,
    'initial_scale' => null,
    'scale_increment' => null,
    'maximum_scale' => null,
    'workforce_per_scale_people' => null,
    'production_definition_key' => null,
    'buildable_terrain_keys' => ['plain'],
];
$classification['behavior'][] = '/facility_definitions/port/buildable_terrain_keys/*';
$classification['behavior'][] = '/facility_definitions/port/scale_unit_people';
$classification['behavior'][] = '/facility_definitions/port/initial_scale';
$classification['behavior'][] = '/facility_definitions/port/scale_increment';
$classification['behavior'][] = '/facility_definitions/port/maximum_scale';
$classification['behavior'][] = '/facility_definitions/port/workforce_per_scale_people';

return [
    'payload' => $payload,
    'classification' => $classification,
];
