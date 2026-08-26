<?php

$domain = require __DIR__.'/../current/monsters-and-military.php';
$payload = $domain['payload'];
$payload['monster_system']['item_drop'] = [
    'random_stream_version' => 1,
    'excluded_monster_keys' => ['mecha_inora', 'mecha_inora_zero'],
    'recipient' => [
        'killer_percent_when_foreign_host' => 75,
        'host_percent_when_foreign_host' => 25,
        'same_or_no_host' => 'killer',
        'inventory_full_reroute' => false,
    ],
    'rarity_pools' => [
        'novice' => [
            'ring', 'secretary_suit', 'inora_bracelet', 'hoarder_talisman',
            'good_person_treasure', 'vault_key', 'monster_repellent_incense', 'fullness_herb',
        ],
        'regular' => ['elf_bow', 'longshot_bow', 'mechanical_bow'],
        'cursed' => ['collar'],
    ],
    'monster_tables' => [
        'inora' => ['rarity_weights' => ['novice' => 70, 'regular' => 25, 'cursed' => 5], 'level_cap_percent' => 30],
        'sanjira' => ['rarity_weights' => ['novice' => 70, 'regular' => 25, 'cursed' => 5], 'level_cap_percent' => 30],
        'red_inora' => ['rarity_weights' => ['novice' => 60, 'regular' => 30, 'cursed' => 10], 'level_cap_percent' => 50],
        'dark_inora' => ['rarity_weights' => ['novice' => 60, 'regular' => 30, 'cursed' => 10], 'level_cap_percent' => 50],
        'inora_ghost' => ['rarity_weights' => ['novice' => 60, 'regular' => 30, 'cursed' => 10], 'level_cap_percent' => 70],
        'aoi_inora' => ['rarity_weights' => ['novice' => 60, 'regular' => 30, 'cursed' => 10], 'level_cap_percent' => 70],
        'whale' => ['rarity_weights' => ['novice' => 50, 'regular' => 35, 'cursed' => 15], 'level_cap_percent' => 80],
        'king_inora' => ['rarity_weights' => ['novice' => 40, 'regular' => 40, 'cursed' => 20], 'level_cap_percent' => 100],
    ],
];

$classification = $domain['classification'];
$classification['behavior'] = array_values(array_unique([
    ...$classification['behavior'],
    '/monster_system/item_drop/excluded_monster_keys/*',
    '/monster_system/item_drop/rarity_pools/novice/*',
    '/monster_system/item_drop/rarity_pools/regular/*',
    '/monster_system/item_drop/rarity_pools/cursed/*',
    'same_or_no_host', 'inventory_full_reroute', 'random_stream_version',
]));
$classification['data'] = array_values(array_unique([
    ...$classification['data'],
    'killer_percent_when_foreign_host', 'host_percent_when_foreign_host',
    'level_cap_percent', 'novice', 'regular', 'cursed',
]));

return [
    'payload' => $payload,
    'classification' => $classification,
];
