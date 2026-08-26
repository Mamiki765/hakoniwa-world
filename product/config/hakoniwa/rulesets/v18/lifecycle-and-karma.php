<?php

$domain = require __DIR__.'/../current/lifecycle-and-karma.php';
$payload = $domain['payload'];
$payload['karma']['impact_points']['undersea_city_destroyed'] = 3;
$payload['karma']['foreign_wasteland_territory_expand'] = 1;

$classification = $domain['classification'];
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'undersea_city_destroyed', 'foreign_wasteland_territory_expand',
]));

return ['payload' => $payload, 'classification' => $classification];
