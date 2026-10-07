<?php

$domain = require __DIR__.'/../release-4.12/secretary.php';
$domain['payload']['secretary']['items']['attraction_towel'] = [
    'key' => 'attraction_towel', 'category' => 'accessory', 'rarity' => 'artifact',
    'tradable' => true, 'npc_tradable' => false, 'max_level' => 10,
    'effects' => [[
        'type' => 'supplemental_attraction', 'source_genre' => 'item',
        'target' => 'post_natural_attraction', 'percent_per_level' => 10,
        'cost_money_per_level' => 100,
    ]],
    'gacha_exception' => false,
];
$domain['classification']['data'][] = 'cost_money_per_level';

return $domain;
