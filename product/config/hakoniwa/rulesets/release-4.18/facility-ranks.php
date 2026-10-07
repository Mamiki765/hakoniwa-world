<?php

$domain = require __DIR__.'/../current/facility-ranks.php';
$domain['payload']['facility_rank_system']['definitions']['city'] = [
    'rank_one_maximum_population' => 20_000,
    'rank_two_name' => '大都市',
    'rank_two_asset_key' => 'tile.large_city',
    'rank_two_effect_description' => '地震・火災を受けず、通常怪獣の自然発生先にならない。',
    'promotion_description' => '人口20,001人以上で自動的にランク2、20,000人以下で通常都市へ',
    'immune_disaster_keys' => ['earthquake', 'fire'],
    'exclude_normal_monster_spawn' => true,
];
$domain['classification']['data'][] = 'rank_one_maximum_population';
$domain['classification']['behavior'][] = '/facility_rank_system/definitions/city/immune_disaster_keys/*';
$domain['classification']['behavior'][] = 'exclude_normal_monster_spawn';

return $domain;
