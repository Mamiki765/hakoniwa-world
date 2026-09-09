<?php

$domain = require __DIR__.'/../v20/secretary.php';
$secretary = $domain['payload']['secretary'];

$secretary['skills']['ship_operations']['level_requirement'] = [
    'basis' => 'next_level_linear',
    'multiplier' => 100,
];

return [
    'payload' => ['secretary' => $secretary],
    'classification' => $domain['classification'],
];
