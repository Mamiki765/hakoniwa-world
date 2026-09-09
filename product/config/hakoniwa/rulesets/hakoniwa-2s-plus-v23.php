<?php

$v22 = require __DIR__.'/hakoniwa-2s-plus-v22.php';
$worldAndMap = (require __DIR__.'/v23/world-and-map.php')['payload'];
$facilities = (require __DIR__.'/v23/facilities.php')['payload'];
$commandsAndProduction = (require __DIR__.'/v23/commands-and-production.php')['payload'];
$centralFacilities = (require __DIR__.'/v23/central-facilities.php')['payload'];
$monstersAndMilitary = (require __DIR__.'/v23/monsters-and-military.php')['payload'];

return [
    ...$v22,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$facilities,
    ...$commandsAndProduction,
    ...$centralFacilities,
    'monster_definitions' => $monstersAndMilitary['monster_definitions'],
    'monster_system' => $monstersAndMilitary['monster_system'],
    'military' => $monstersAndMilitary['military'],
];
