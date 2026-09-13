<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['new'];
$plans = [
    'martial' => ['standard' => standardSkills('martial', 100), 'area' => ['precision_cut', 'whirlwind', 'executioner_cut', 'quick_stab', 'armor_break_strike']],
    'guardianship' => ['standard' => standardSkills('guardianship', 100), 'area' => ['shield_bash', 'counter_stance', 'bulwark_strike', 'unbroken_retort', 'sundering_shield']],
    'miracle' => ['standard' => standardSkills('miracle', 100), 'area' => ['holy_bolt', 'holy_nova', 'holy_lance', 'lucid_dream', 'crystal_cycle']],
];
$manifest = $players->explorationCatalog()->manifest();
$manifest['skills']['benchmark_idle'] = ['label' => '木人待機', 'mp_cost' => 0, 'cooldown' => 0, 'effects' => [['type' => 'mp_restore', 'target' => 'self', 'amount' => 0]]];
$manifest['enemies']['area_dummy'] = measurementEnemy(90);
$catalog = new AlphaV1BuildCatalog($manifest);
$out = ['rounds' => 100, 'seeds' => 32, 'cases' => []];
foreach ($inputs as $case) {
    if ($case['level'] !== 90) {
        continue;
    }
    foreach ($plans[$case['tree']] as $style => $skills) {
        $p = rebuildSnapshot(standardRoleSnapshot($case), $skills, 100)['snapshot'];
        $p['combatant_id'] = 'synthetic:'.$case['tree'];
        foreach ([1, 4] as $count) {
            $damage = [];
            for ($seed = 31000; $seed < 31032; $seed++) {
                $r = $model->fightPartySnapshots($catalog, [$p], array_fill(0, $count, 'area_dummy'), $seed, 100, 300);
                $damage[] = $r->metrics['damage_dealt'] / 100;
            }
            $out['cases'][] = ['tree' => $case['tree'], 'style' => $style, 'enemies' => $count, 'skills' => $skills, 'dpr' => distribution($damage)];
        }
    }
}
file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
