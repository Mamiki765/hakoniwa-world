<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
if (! in_array($argv[1] ?? '', ['old', 'before', 'after'], true) || ($argv[2] ?? '') === '') {
    throw new InvalidArgumentException('Usage: php roles.php old|before|after output.json');
}
$variant = $argv[1];
$maxRounds = (int) ($argv[3] ?? 100);
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$cases = [];
foreach ($inputs[$variant === 'old' ? 'old' : 'new'] as $case) {
    $case['snapshot'] = standardRoleSnapshot($case);
    if ($variant !== 'old') {
        $case = [...$case, ...rebuildSnapshot($case['snapshot'], standardSkills($case['tree'], $case['sp_budget']), $case['sp_budget'])];
    }
    $cases[] = [...$case, 'style' => 'standard'];
    if ($variant !== 'old' && $case['level'] === 90) {
        $plans = match ($case['tree']) {
            'martial' => ['additional_strike' => ['precision_cut', 'armor_break_strike', 'iaido_cut', 'executioner_cut', 'quick_stab']],
            'guardianship' => ['attack' => ['shield_bash', 'counter_stance', 'bulwark_strike', 'unbroken_retort', 'sundering_shield']],
            'miracle' => ['attack' => ['holy_bolt', 'holy_nova', 'holy_lance', 'lucid_dream', 'crystal_cycle'],
                'support' => ['mending_prayer', 'regeneration', 'resurrection', 'cleansing_wave', 'crystal_cycle']],
        };
        foreach ($plans as $style => $skills) {
            $cases[] = [...$case, ...rebuildSnapshot($case['snapshot'], $skills, $case['sp_budget']), 'style' => $style];
        }
    }
}
$out = ['variant' => $variant, 'fixture' => 'passive_no_damage_no_guard', 'rounds' => $maxRounds, 'seed_start' => 31000, 'seeds' => 32, 'cases' => []];
foreach ($cases as $case) {
    $manifest = $players->explorationCatalog()->manifest();
    $manifest['skills']['benchmark_idle'] = ['label' => '木人待機', 'mp_cost' => 0, 'cooldown' => 0,
        'effects' => [['type' => 'mp_restore', 'target' => 'self', 'amount' => 0]]];
    $manifest['enemies']['role_dummy'] = measurementEnemy($case['level']);
    $catalog = new AlphaV1BuildCatalog($manifest);
    $damage = [];
    $usage = [];
    $blocked = 0;
    $mp = [];
    for ($seed = 31000; $seed < 31032; $seed++) {
        $result = $model->fightPlayerSnapshot($catalog, $case['snapshot'], 'role_dummy', $seed, $maxRounds, 300);
        $damage[] = $result->damageDealt / $maxRounds;
        $blocked += $result->skillUnavailableDueToMp;
        $mp[] = $result->finalMp;
        foreach ($result->actionUsage as $key => $count) {
            $usage[$key] = ($usage[$key] ?? 0) + $count / 32;
        }
    }
    $out['cases'][] = ['tree' => $case['tree'], 'style' => $case['style'], 'level' => $case['level'],
        'budget' => $case['sp_budget'], 'spent' => $case['spent'], 'active_skills' => $case['snapshot']['active_skills'],
        'dpr' => distribution($damage), 'mean_actions' => $usage, 'mp_blocked' => $blocked / 32, 'final_mp' => distribution($mp)];
}
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
