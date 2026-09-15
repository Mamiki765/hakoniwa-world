<?php

$domain = require __DIR__.'/../v25/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v26';
$payload['version'] = 26;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
