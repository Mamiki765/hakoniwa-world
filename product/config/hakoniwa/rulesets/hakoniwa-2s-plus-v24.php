<?php

$v23 = require __DIR__.'/hakoniwa-2s-plus-v23.php';
$worldAndMap = (require __DIR__.'/v24/world-and-map.php')['payload'];
$commandsAndProduction = (require __DIR__.'/v24/commands-and-production.php')['payload'];
$turnPipeline = (require __DIR__.'/v24/turn-pipeline.php')['payload'];
$turnProcessing = $v23['turn_processing'];
$turnProcessing['territory_influence']['acquisition_land_limit']
    = $turnPipeline['turn_processing']['territory_influence']['acquisition_land_limit'];

return [
    ...$v23,
    'key' => $worldAndMap['key'],
    'version' => $worldAndMap['version'],
    'initial_island_placement' => $worldAndMap['initial_island_placement'],
    ...$commandsAndProduction,
    'turn_processing' => $turnProcessing,
];
