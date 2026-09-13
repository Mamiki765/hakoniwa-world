<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
[$script, $variant, $outputPath] = $argv;
$input = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$base = array_values(array_filter($input[$variant === 'old' ? 'old' : 'new'], static fn (array $row): bool => $row['tree'] === 'guardianship' && $row['level'] === 90))[0];
$manifest = $players->explorationCatalog()->manifest();
$manifest['skills']['benchmark_idle'] = ['label' => '木人待機', 'mp_cost' => 0, 'cooldown' => 0, 'effects' => [['type' => 'mp_restore', 'target' => 'self', 'amount' => 0]]];
$manifest['enemies']['allocation_dummy'] = measurementEnemy(90);
if (isset($argv[3])) {
    $mightCoefficient = (int) $argv[3];
    foreach (['shield_bash', 'bulwark_strike', 'unbroken_retort', 'sundering_shield'] as $skill) {
        foreach ($manifest['skills'][$skill]['effects'] as &$effect) {
            if ($effect['type'] === 'damage') {
                $effect['stat_coefficients'] = ['vitality' => 10000 - $mightCoefficient, 'might' => $mightCoefficient];
            }
        }
        unset($effect);
    }
}
$catalog = new AlphaV1BuildCatalog($manifest);
$out = ['variant' => $variant, 'level' => 90, 'rounds' => 100, 'seeds' => 32, 'cases' => []];
foreach ([0, 25, 50, 75, 100] as $mightShare) {
    $p = $base['snapshot'];
    unset($p['current_hp'], $p['awakening']);
    $stp = array_fill_keys($rules::STATS, 0);
    $budget = $players->stpEntitlement('guardianship_blue', 90);
    $stp['might'] = intdiv($budget * $mightShare, 100);
    $stp['vitality'] = $budget - $stp['might'];
    $p['stats'] = $players->currentStats('guardianship_blue', 90, $stp);
    if ($variant !== 'old') {
        $p = rebuildSnapshot($p, standardSkills('guardianship', 100), 100)['snapshot'];
    }
    $damage = [];
    $normal = [];
    for ($seed = 31000; $seed < 31032; $seed++) {
        $r = $model->fightPlayerSnapshot($catalog, $p, 'allocation_dummy', $seed, 100, 300);
        $damage[] = $r->damageDealt / 100;
        $normal[] = $r->actionUsage['normal_attack'];
    }
    $out['cases'][] = ['might_share' => $mightShare, 'dpr' => distribution($damage), 'normal_attacks' => distribution($normal),
        'hp' => $players->maxHp($players->combatStats($p['stats'], $p['equipment']), $p['equipment'])];
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
