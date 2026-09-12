<?php

$domain = require __DIR__.'/../v23/world-and-map.php';
$payload = $domain['payload'];
$payload['key'] = 'hakoniwa-2s-plus-v24';
$payload['version'] = 24;
$payload['initial_island_placement'] = [
    'reservation_terrain_keys' => ['sea', 'shallow', 'wasteland', 'mountain'],
    'ship_relocation' => 'final_empty_sea_within_reservation',
];
$classification = $domain['classification'];
$classification['behavior'][] = '/initial_island_placement/reservation_terrain_keys/*';
$classification['behavior'][] = '/initial_island_placement/ship_relocation';

return [
    'payload' => $payload,
    'classification' => $classification,
];
