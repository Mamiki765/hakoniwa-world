<?php

$domain = require __DIR__.'/../v17/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v18';
$payload['version'] = 18;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
