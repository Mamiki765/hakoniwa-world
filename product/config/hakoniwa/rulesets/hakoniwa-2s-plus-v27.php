<?php

$v26 = require __DIR__.'/hakoniwa-2s-plus-v26.php';
$worldAndMap = (require __DIR__.'/v27/world-and-map.php')['payload'];
$secretary = (require __DIR__.'/v27/secretary.php')['payload'];
$monstersAndMilitary = (require __DIR__.'/v27/monsters-and-military.php')['payload'];
$oceanLoop = (require __DIR__.'/v27/ocean-loop.php')['payload'];

return [
    ...$v26,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$secretary,
    'monster_system' => $monstersAndMilitary['monster_system'],
    'ocean_loop' => $oceanLoop['ocean_loop'],
];
