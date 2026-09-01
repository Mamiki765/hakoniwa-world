<?php

$v18 = require __DIR__.'/hakoniwa-2s-plus-v18.php';
$worldAndMap = (require __DIR__.'/v19/world-and-map.php')['payload'];
$commandsAndProduction = (require __DIR__.'/v19/commands-and-production.php')['payload'];
$undergroundFacilities = (require __DIR__.'/v19/underground-facilities.php')['payload'];

return [
    ...$v18,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    'command_definitions' => $commandsAndProduction['command_definitions'],
    'production_definitions' => $commandsAndProduction['production_definitions'],
    'underground_facility_development' => $undergroundFacilities['underground_facility_development'],
];
