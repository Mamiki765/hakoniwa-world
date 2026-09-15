<?php

$domain = require __DIR__.'/../v22/secretary.php';
$secretary = $domain['payload']['secretary'];

$secretary['skills']['navy'] = [
    'key' => 'navy',
    'name' => '海軍',
    'initial_level' => 0,
    'level_requirement' => [
        'basis' => 'next_level_linear',
        'multiplier' => 30,
    ],
    'effect' => [
        'type' => 'placeholder',
        'display' => '効果なし',
    ],
    'experience_source' => [
        'type' => 'successful_warship_hit',
        'target_experience' => 'normal_missile_hit_equivalent',
    ],
];

$secretary['item_categories']['ticket'] = ['key' => 'ticket', 'max_equipped' => 0];
$secretary['item_rarities']['high_quality'] = [
    'key' => 'high_quality',
    'name' => 'ハイクオリティ',
    'fixed_sale_price_money' => 1500,
];
$secretary['items']['wakuwaku_ticket'] = [
    'key' => 'wakuwaku_ticket',
    'category' => 'ticket',
    'rarity' => 'regular',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 1,
    'effects' => [],
];
$secretary['items']['dokidoki_ticket'] = [
    'key' => 'dokidoki_ticket',
    'category' => 'ticket',
    'rarity' => 'high_quality',
    'tradable' => true,
    'npc_tradable' => false,
    'max_level' => 1,
    'effects' => [],
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    'target_experience',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'], 'fixed_sale_price_money',
]));

return [
    'payload' => ['secretary' => $secretary],
    'classification' => $classification,
];
