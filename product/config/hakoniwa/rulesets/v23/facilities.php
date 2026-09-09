<?php

$domain = require __DIR__.'/../v20/facilities.php';
$payload = $domain['payload'];
$classification = $domain['classification'];

foreach ([
    'central_bank' => [
        'name' => '中央銀行',
        'asset_key' => 'tile.central_bank',
        'build_command_key' => 'build_central_bank',
    ],
    'central_granary' => [
        'name' => '中央穀倉',
        'asset_key' => 'tile.central_granary',
        'build_command_key' => 'build_central_granary',
    ],
] as $facilityKey => $identity) {
    $payload['facility_definitions'][$facilityKey] = [
        'name' => $identity['name'],
        'asset_key' => $identity['asset_key'],
        'visibility_policy' => 'disguised',
        'disguise_terrain_key' => 'forest',
        'disguise_asset_key' => 'tile.forest',
        'disguise_ownership_policy' => null,
        'build_command_key' => $identity['build_command_key'],
        'scale_unit_people' => 1,
        'initial_scale' => 1,
        'scale_increment' => 1,
        'maximum_scale' => 90,
        'workforce_per_scale_people' => 0,
        'production_definition_key' => null,
        'buildable_terrain_keys' => ['plain'],
    ];
    foreach (['scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people'] as $field) {
        $classification['data'][] = "/facility_definitions/{$facilityKey}/{$field}";
    }
    $classification['behavior'][] = "/facility_definitions/{$facilityKey}/buildable_terrain_keys/*";
}

return ['payload' => $payload, 'classification' => $classification];
