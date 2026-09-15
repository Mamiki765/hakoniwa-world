<?php

$v25 = require __DIR__.'/hakoniwa-2s-plus-v25.php';
$worldAndMap = (require __DIR__.'/v26/world-and-map.php')['payload'];
$surfaceShips = (require __DIR__.'/v26/surface-ships.php')['payload'];
$secretary = (require __DIR__.'/v26/secretary.php')['payload'];
$monstersAndMilitary = (require __DIR__.'/v26/monsters-and-military.php')['payload'];
$oceanLoop = (require __DIR__.'/v26/ocean-loop.php')['payload'];

return [
    ...$v25,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$surfaceShips,
    ...$secretary,
    'monster_definitions' => $monstersAndMilitary['monster_definitions'],
    'monster_system' => $monstersAndMilitary['monster_system'],
    'military' => $monstersAndMilitary['military'],
    ...$oceanLoop,
];
