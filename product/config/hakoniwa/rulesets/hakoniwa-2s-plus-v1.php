<?php

$ruleset = require __DIR__.'/roadmap-pr22-v1.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v1';
$ruleset['version'] = 1;

$commandDescriptions = [
    'land_level' => '所有する陸地を、ターンを消費せず平地にします。ごくまれに地震が発生することがあります。',
    'build_farm' => '平地へ農場を建設します。',
    'build_factory' => '平地へ工場を建設します。',
    'build_mine' => '山へ採掘場を建設します。',
    'attraction' => '誘致活動を行ったターンは、人口が増加しやすくなります。',
    'food_aid' => '対象の国に、数量×1,000トンの食料を援助します。',
    'money_aid' => '対象の国に、数量×100億円の資金を援助します。',
    'monster_dispatch' => '対象の国にメカいのらを派遣します。',
    'finance' => '何もせず、資金繰りを行います。',
    'missile' => '指定座標へミサイルを発射します。数量で発射本数を設定します。誤差範囲は周囲2マスです。PPミサイルと同じターンに使用できます。',
    'pp_missile' => '指定座標へPPミサイルを発射します。数量で発射本数を設定します。誤差範囲は周囲1マスです。通常のミサイルと同じターンに使用できます。',
    'land_destruction_missile' => '指定座標へ大地をえぐる陸地破壊弾を発射します。数量で発射本数を設定します。誤差範囲は周囲2マスです。他のミサイルとは同じターンに使用できません。',
    'spp_missile' => '指定座標へSPPミサイルを発射します。数量で発射本数を設定します。着弾地点に誤差はありません。他のミサイルとは同じターンに使用できません。',
];
$ruleset['command_definitions'] = array_map(
    static function (array $definition) use ($commandDescriptions): array {
        $description = $commandDescriptions[$definition['key']] ?? null;

        return $description === null ? $definition : [...$definition, 'description' => $description];
    },
    $ruleset['command_definitions'],
);
$ruleset['monster_definitions'] = array_map(
    static function (array $definition): array {
        return $definition['key'] === 'inora_ghost'
            ? [...$definition, 'skill_description' => '1ターンに最大？歩移動']
            : $definition;
    },
    $ruleset['monster_definitions'],
);

return $ruleset;
