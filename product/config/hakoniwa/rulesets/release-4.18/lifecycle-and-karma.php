<?php

$domain = require __DIR__.'/../current/lifecycle-and-karma.php';
$domain['payload']['karma']['impact_points']['undersea_fire_station_destroyed'] = 3;
$domain['classification']['data'][] = 'undersea_fire_station_destroyed';

return $domain;
