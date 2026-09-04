<?php

$domain = require __DIR__.'/../v17/secretary.php';
$secretary = $domain['payload']['secretary'];

$secretary['skills']['ship_operations'] = [
    'key' => 'ship_operations',
    'name' => '船舶運用',
    'initial_level' => 0,
    'level_requirement' => [
        'basis' => 'next_level_squared',
        'multiplier' => 65_535,
    ],
    'effect' => [
        'type' => 'placeholder',
        'display' => '準備中',
    ],
    'experience_source' => [
        'type' => 'successful_ship_movement',
        'points_per_execution' => 1,
        'quantity_multiplier' => false,
    ],
];

$classification = $domain['classification'];
$classification['flavor'] = array_values(array_unique([
    ...$classification['flavor'],
    'display',
]));

return [
    'payload' => ['secretary' => $secretary],
    'classification' => $classification,
];
