<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['new'];
$base = array_values(array_filter($inputs, static fn (array $row): bool => $row['tree'] === 'martial' && $row['level'] === 90))[0];
$manifest = $players->explorationCatalog()->manifest();
$manifest['skills']['benchmark_idle'] = ['label' => '木人待機', 'mp_cost' => 0, 'cooldown' => 0, 'effects' => [['type' => 'mp_restore', 'target' => 'self', 'amount' => 0]]];
$manifest['enemies']['cross_dummy'] = measurementEnemy(90);
$catalog = new AlphaV1BuildCatalog($manifest);
$out = [];
foreach ([
    'martial_standard' => standardSkills('martial', 100),
    'shield_only' => ['shield_bash'],
    'shield_and_break' => ['shield_bash', 'armor_break_strike'],
    'guardian_standard' => standardSkills('guardianship', 100),
    'mixed_attacks' => ['shield_bash', 'armor_break_strike', 'executioner_cut', 'bulwark_strike', 'precision_cut'],
] as $style => $skills) {
    $rebuilt = rebuildSnapshot($base['snapshot'], $skills, 100);
    $damage = [];
    for ($seed = 31000; $seed < 31032; $seed++) {
        $r = $model->fightPlayerSnapshot($catalog, $rebuilt['snapshot'], 'cross_dummy', $seed, 100, 300);
        $damage[] = $r->damageDealt / 100;
    }
    $out[] = ['style' => $style, 'skills' => $skills, 'spent' => $rebuilt['spent'], 'dpr' => distribution($damage)];
}
file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
