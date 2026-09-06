<?php

$v20 = require __DIR__.'/hakoniwa-2s-plus-v20.php';
$worldAndMap = (require __DIR__.'/v21/world-and-map.php')['payload'];
$facilityRanks = (require __DIR__.'/v21/facility-ranks.php')['payload'];
$lifecycleAndKarma = (require __DIR__.'/v21/lifecycle-and-karma.php')['payload'];
$monstersAndMilitary = (require __DIR__.'/v21/monsters-and-military.php')['payload'];

return [
    ...$v20,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    ...$facilityRanks,
    ...$lifecycleAndKarma,
    ...$monstersAndMilitary,
];
