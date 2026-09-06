<?php

$domain = require __DIR__.'/../v20/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v21';
$payload['version'] = 21;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
