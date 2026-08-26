<?php

$domain = require __DIR__.'/../current/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v17';
$payload['version'] = 17;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
