<?php

$domain = require __DIR__.'/../v26/ocean-loop.php';
$payload = $domain['payload'];
$payload['ocean_loop']['buried_treasure']['emblem_replacements'] = [
    'meteor' => [
        'chance_percent' => 5,
        'reward' => [
            'item_key' => 'twin_star_emblem', 'quantity' => 1,
            'rarity' => 'artifact', 'fixed_sale_price_money' => 3000,
        ],
    ],
    'pirate_sink' => [
        'minimum_population' => 20_000,
        'reward' => [
            'item_key' => 'crescent_emblem', 'quantity' => 1,
            'rarity' => 'artifact', 'fixed_sale_price_money' => 3000,
        ],
    ],
];

$classification = $domain['classification'];
$classification['data'][] = '/ocean_loop/buried_treasure/emblem_replacements/meteor/chance_percent';
$classification['data'][] = '/ocean_loop/buried_treasure/emblem_replacements/pirate_sink/minimum_population';

return ['payload' => $payload, 'classification' => $classification];
