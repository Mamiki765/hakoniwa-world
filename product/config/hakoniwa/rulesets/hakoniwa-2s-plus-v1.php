<?php

$ruleset = require __DIR__.'/roadmap-pr22-v1.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v1';
$ruleset['version'] = 1;

$commandDescriptions = [
    'land_level' => '所有する陸地をターンを消費せず平地にします。',
    'build_farm' => '平地へ農場を建設します。',
    'build_factory' => '平地へ工場を建設します。',
    'build_mine' => '山へ採掘場を建設します。',
    'finance' => '資金繰りを行い、10億円を受け取ります。',
    'attraction' => '次のターン、人口が増加しやすくなります。',
];
foreach ($ruleset['command_definitions'] as $index => $definition) {
    $description = $commandDescriptions[$definition['key']] ?? null;
    if ($description !== null) {
        $ruleset['command_definitions'][$index]['description'] = $description;
    }
}
foreach ($ruleset['monster_definitions'] as $index => $definition) {
    if ($definition['key'] === 'inora_ghost') {
        $ruleset['monster_definitions'][$index]['skill_description'] = '1ターンに何歩も移動することがあります';
    }
}

return $ruleset;
