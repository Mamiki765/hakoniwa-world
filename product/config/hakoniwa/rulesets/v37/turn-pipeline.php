<?php

$domain = require __DIR__.'/../current/turn-pipeline.php';
$domain['payload']['turn_processing']['riot']['facility_keys'] = array_values(array_filter(
    $domain['payload']['turn_processing']['riot']['facility_keys'],
    static fn (string $key): bool => $key !== 'seabed_base',
));

return $domain;
