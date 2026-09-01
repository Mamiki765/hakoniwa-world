<?php

$domain = require __DIR__.'/../v18/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v19';
$payload['version'] = 19;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
