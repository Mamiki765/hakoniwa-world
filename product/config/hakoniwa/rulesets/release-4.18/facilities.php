<?php

$domain = require __DIR__.'/../release-4.11/facilities.php';
$domain['payload']['facility_definitions']['undersea_fire_station'] = [
    'name' => '海底消防署', 'asset_key' => 'tile.undersea_fire_station',
    'visibility_policy' => 'disguised', 'disguise_terrain_key' => 'sea',
    'disguise_asset_key' => 'tile.sea', 'disguise_ownership_policy' => 'neutral',
    'build_command_key' => 'build_undersea_fire_station',
    'scale_unit_people' => null, 'initial_scale' => null, 'scale_increment' => null,
    'maximum_scale' => null, 'workforce_per_scale_people' => null,
    'production_definition_key' => null, 'buildable_terrain_keys' => ['sea'],
];
foreach (['scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people'] as $field) {
    $domain['classification']['behavior'][] = '/facility_definitions/undersea_fire_station/'.$field;
}
$domain['classification']['behavior'][] = '/facility_definitions/undersea_fire_station/buildable_terrain_keys/*';

return $domain;
