<?php

$domain = require __DIR__.'/../v19/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v20';
$payload['version'] = 20;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
