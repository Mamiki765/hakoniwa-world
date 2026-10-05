<?php

$domain = require __DIR__.'/../release-4.13/terrain-and-disasters.php';
$domain['payload']['turn_processing']['disasters']['fire']['facility_keys'] = array_values(array_diff(
    $domain['payload']['turn_processing']['disasters']['fire']['facility_keys'],
    ['thermal_power'],
));

return $domain;
