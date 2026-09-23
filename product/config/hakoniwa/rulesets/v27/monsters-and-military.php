<?php

$domain = require __DIR__.'/../v26/monsters-and-military.php';
$payload = $domain['payload'];
$drop = &$payload['monster_system']['item_drop'];
$drop['rarity_pools']['high_quality'] = [
    'star_charm', 'gem_bow', 'aquamarine_bow', 'bullseye_bow',
    'eternal_suit', 'marshal_suit', 'grand_chancellor_suit',
    'magic_white_flag', 'nyowamiya_ribbon',
];
$drop['nyowamiya_love_emblem_replacement_percent'] = 20;
foreach ($drop['monster_tables'] as $monsterKey => &$table) {
    $highQuality = in_array($monsterKey, ['inora_ghost', 'whale', 'king_inora', 'nyowamiya'], true) ? 2 : 1;
    $table['rarity_weights']['novice'] -= $highQuality;
    $table['rarity_weights']['high_quality'] = $highQuality;
}
unset($table, $drop);

$classification = $domain['classification'];
$classification['behavior'][] = '/monster_system/item_drop/rarity_pools/high_quality/*';
$classification['data'][] = '/monster_system/item_drop/nyowamiya_love_emblem_replacement_percent';
foreach (array_keys($payload['monster_system']['item_drop']['monster_tables']) as $monsterKey) {
    $classification['data'][] = "/monster_system/item_drop/monster_tables/{$monsterKey}/rarity_weights/high_quality";
}

return ['payload' => $payload, 'classification' => $classification];
