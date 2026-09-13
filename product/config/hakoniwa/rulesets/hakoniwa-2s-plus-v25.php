<?php

$v24 = require __DIR__.'/hakoniwa-2s-plus-v24.php';
$worldAndMap = (require __DIR__.'/v25/world-and-map.php')['payload'];

return [
    ...$v24,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    'initial_island_placement' => $worldAndMap['initial_island_placement'],
];
