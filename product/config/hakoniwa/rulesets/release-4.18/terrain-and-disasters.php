<?php

$domain = require __DIR__.'/../natural-fire-fix/terrain-and-disasters.php';
$domain['payload']['capital_growth_maximum_population'] = 40500;
foreach (['excluded_facility_keys', 'water_facility_keys'] as $field) {
    $domain['payload']['turn_processing']['disasters']['tsunami'][$field][] = 'undersea_fire_station';
}
foreach (['meteor_shower', 'huge_meteor', 'eruption'] as $key) {
    $domain['payload']['turn_processing']['disasters'][$key]['seabed_facility_keys'][] = 'undersea_fire_station';
}
$domain['payload']['turn_processing']['disasters']['fire']['undersea_protection'] = [
    'facility_key' => 'undersea_fire_station', 'radius' => 2, 'cost_money' => 100,
    'selection' => 'affordable_owner_map_cell_id_ascending',
    'target_owner' => 'station_owner',
];
$domain['classification']['behavior'][] = 'selection';
$domain['classification']['behavior'][] = 'target_owner';
$domain['classification']['data'][] = 'cost_money';

return $domain;
