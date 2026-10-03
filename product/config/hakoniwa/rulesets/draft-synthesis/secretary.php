<?php

// Unpublished proposal. The current application and baseline still select v27.
$domain = require __DIR__.'/../current/secretary.php';
$domain['payload']['secretary']['items']['succubus_emblem'] = [
    'key' => 'succubus_emblem',
    'category' => 'accessory',
    'rarity' => 'relic',
    'tradable' => false,
    'npc_tradable' => false,
    'max_level' => 1,
    'effects' => [[
        'type' => 'population_growth_percent',
        'percent' => 100,
        'applies_to' => ['ordinary', 'attraction'],
    ]],
    'gacha_exception' => true,
];
$domain['payload']['secretary']['item_synthesis'] = [
    'succubus_emblem' => [
        'key' => 'succubus_emblem',
        'ingredient_keys' => ['love_emblem', 'twin_star_emblem', 'crescent_emblem'],
        'ingredient_level' => 1,
        'result_key' => 'succubus_emblem',
        'result_level' => 1,
        'cost_money' => 0,
        'disclosure' => 'owns_all_ingredients',
    ],
];
$domain['classification']['behavior'][] = '/secretary/items/succubus_emblem/effects/*/applies_to/*';
$domain['classification']['behavior'][] = '/secretary/item_synthesis/succubus_emblem/ingredient_keys/*';
$domain['classification']['behavior'][] = 'result_key';
$domain['classification']['behavior'][] = 'disclosure';
$domain['classification']['data'][] = 'ingredient_level';
$domain['classification']['data'][] = 'result_level';
$domain['classification']['data'][] = 'cost_money';

return $domain;
