<?php

$domain = require __DIR__.'/../v23/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v24';
$payload['version'] = 24;

return [
    'payload' => $payload,
    'classification' => $domain['classification'],
];
