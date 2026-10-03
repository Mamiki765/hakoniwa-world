<?php

$domain = require __DIR__.'/../current/terrain-and-disasters.php';
$domain['payload']['turn_processing']['disasters']['fire']['facility_keys'][] = 'pizzeria';
$domain['payload']['turn_processing']['disasters']['fire']['facility_keys'][] = 'thermal_power';
foreach (['earthquake', 'tsunami'] as $key) {
    array_push($domain['payload']['turn_processing']['disasters'][$key]['facility_keys'], 'wind_power', 'thermal_power', 'condenser', 'pizzeria');
}
$domain['payload']['turn_processing']['disasters']['typhoon']['facility_keys'][] = 'wind_power';

return $domain;
