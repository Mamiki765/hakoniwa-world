<?php

$domain = require __DIR__.'/../v22/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v23';
$payload['version'] = 23;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
