<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v33.php';
$world = (require __DIR__.'/release-4.15/world-and-map.php')['payload'];
$ships = (require __DIR__.'/release-4.15/surface-ships.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['surface_ships'] = $ships['surface_ships'];

return $rules;
