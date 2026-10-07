<?php

$domain = require __DIR__.'/../current/surface-ships.php';
$domain['payload']['surface_ships']['definitions']['exploration']['visibility_radius'] = 5;
$domain['payload']['surface_ships']['definitions']['warship']['visibility_radius'] = 2;

return $domain;
