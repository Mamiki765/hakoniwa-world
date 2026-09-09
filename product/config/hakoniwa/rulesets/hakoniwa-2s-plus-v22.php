<?php

$v21 = require __DIR__.'/hakoniwa-2s-plus-v21.php';
$worldAndMap = (require __DIR__.'/v22/world-and-map.php')['payload'];
$secretary = (require __DIR__.'/v22/secretary.php')['payload'];

return [
    ...$v21,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$secretary,
];
