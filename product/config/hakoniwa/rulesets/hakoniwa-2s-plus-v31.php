<?php

$rules = require __DIR__.'/hakoniwa-2s-plus-v30.php';
$world = (require __DIR__.'/release-4.13/world-and-map.php')['payload'];
$terrain = (require __DIR__.'/release-4.13/terrain-and-disasters.php')['payload'];
$rules['key'] = $world['key'];
$rules['version'] = $world['version'];
$rules['turn_processing']['disasters'] = $terrain['turn_processing']['disasters'];
$rules['turn_processing']['sea_area_weather'] = $terrain['turn_processing']['sea_area_weather'];

return $rules;
