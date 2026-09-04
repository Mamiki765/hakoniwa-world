<?php

$v19 = require __DIR__.'/hakoniwa-2s-plus-v19.php';
$worldAndMap = (require __DIR__.'/v20/world-and-map.php')['payload'];
$facilities = (require __DIR__.'/v20/facilities.php')['payload'];
$commandsAndProduction = (require __DIR__.'/v20/commands-and-production.php')['payload'];

return [
    ...$v19,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$facilities,
    ...$commandsAndProduction,
];
