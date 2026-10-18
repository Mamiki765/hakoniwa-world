<?php

$domain = require __DIR__.'/../v39/secretary.php';
$secretary = &$domain['payload']['secretary'];
$keys = ['fire_charm', 'wave_charm', 'wind_charm', 'quake_charm', 'star_charm', 'wakuwaku_ticket', 'dokidoki_ticket'];
foreach ($keys as $key) {
    $secretary['items'][$key]['synthesis_level_cap'] = 9999;
    $secretary['synthesis']['recipes']['stack:'.$key] = [
        'key' => 'stack:'.$key, 'kind' => 'stack', 'item_key' => $key, 'level_cap' => 9999,
    ];
}
$secretary['items']['succubus_emblem'] = [
    'key' => 'succubus_emblem', 'category' => 'accessory', 'rarity' => 'relic',
    'tradable' => true, 'npc_tradable' => false, 'max_level' => 1,
    'effects' => [['type' => 'population_growth_percent', 'percent' => 100, 'applies_to' => ['ordinary', 'attraction']]],
    'gacha_exception' => true,
];
$secretary['synthesis']['recipes']['succubus_emblem'] = [
    'key' => 'succubus_emblem', 'kind' => 'fixed',
    'inputs' => array_map(static fn (string $key): array => ['item_key' => $key, 'level' => 1, 'count' => 1],
        ['love_emblem', 'twin_star_emblem', 'crescent_emblem']),
    'output' => ['item_key' => 'succubus_emblem', 'level' => 1], 'cost_money' => 0,
];
$secretary['warehouse'] = ['base_capacity' => 50, 'expansion_slots' => 50, 'expansion_cost_paradox' => 100, 'max_expansions' => 4];
$domain['classification']['behavior'] = [...$domain['classification']['behavior'],
    'kind', 'item_key', '/secretary/items/succubus_emblem/effects/*/applies_to/*'];
$domain['classification']['data'] = [...$domain['classification']['data'],
    'synthesis_level_cap', 'level_cap', 'cost_money', 'count', 'level',
    'base_capacity', 'expansion_slots', 'expansion_cost_paradox', 'max_expansions'];

return $domain;
