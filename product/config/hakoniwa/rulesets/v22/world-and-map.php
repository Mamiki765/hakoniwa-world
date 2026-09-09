<?php

$domain = require __DIR__.'/../v21/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v22';
$payload['version'] = 22;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
