<?php

$v25 = require __DIR__.'/hakoniwa-2s-plus-v25.php';
$worldAndMap = (require __DIR__.'/v26/world-and-map.php')['payload'];
$surfaceShips = (require __DIR__.'/v26/surface-ships.php')['payload'];

return [
    ...$v25,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$surfaceShips,
];
