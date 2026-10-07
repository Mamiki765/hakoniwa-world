<?php

$domain = require __DIR__.'/../current/turn-pipeline.php';
$domain['payload']['turn_processing']['undersea_fire_station_maintenance'] = [
    'facility_key' => 'undersea_fire_station', 'cost_money' => 6,
    'settlement_order' => 'map_cell_id_ascending',
    'settlement_stage' => 'after_undersea_city_maintenance',
    'failure_terrain_key' => 'sea', 'failure_ownership_policy' => 'neutral',
];
foreach (['settlement_stage', 'failure_terrain_key', 'failure_ownership_policy'] as $field) {
    $domain['classification']['behavior'][] = $field;
}
$domain['classification']['data'][] = 'cost_money';

return $domain;
