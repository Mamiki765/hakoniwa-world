<?php

$domain = require __DIR__.'/../v26/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v27';
$payload['version'] = 27;

return ['payload' => $payload, 'classification' => $domain['classification']];
