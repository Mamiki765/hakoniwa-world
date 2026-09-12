<?php

$v23 = require __DIR__.'/hakoniwa-2s-plus-v23.php';
$worldAndMap = (require __DIR__.'/v24/world-and-map.php')['payload'];
$commandsAndProduction = (require __DIR__.'/v24/commands-and-production.php')['payload'];

return [
    ...$v23,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$commandsAndProduction,
];
