<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['new'];
$out = ['seeds' => 256, 'seed_start' => 31000, 'rounds' => 100, 'cases' => []];
foreach ([30, 90, 484] as $level) {
    foreach (['martial' => 'might', 'miracle' => 'spirit'] as $tree => $primary) {
        $base = array_values(array_filter($inputs, static fn (array $row): bool => $row['tree'] === $tree && $row['level'] === ($level === 484 ? 90 : $level)))[0];
        $manifest = $players->explorationCatalog()->manifest();
        $manifest['skills']['benchmark_idle'] = ['label' => '木人待機', 'mp_cost' => 0, 'cooldown' => 0, 'effects' => [['type' => 'mp_restore', 'target' => 'self', 'amount' => 0]]];
        $manifest['enemies']['finesse_dummy'] = measurementEnemy($level);
        $catalog = new AlphaV1BuildCatalog($manifest);
        foreach ([0, 30, 50] as $share) {
            $p = $base['snapshot'];
            unset($p['current_hp'], $p['awakening']);
            // Same total primary+finesse budget; all other stats and gear are fixed.
            $budget = $level * 10;
            $p['stats'][$primary] = $budget - max(1, intdiv($budget * $share, 100));
            $p['stats']['finesse'] = max(1, intdiv($budget * $share, 100));
            $p = rebuildSnapshot($p, standardSkills($tree, $level === 30 ? 20 : 100), $level === 30 ? 20 : 100)['snapshot'];
            $first = [];
            $largest = [];
            $burst = [];
            $totals = [];
            for ($seed = 31000; $seed < 31256; $seed++) {
                $r = $model->fightPlayerSnapshot($catalog, $p, 'finesse_dummy', $seed, 100, 300);
                $rows = array_values(array_filter($r->actionLog, static fn (array $row): bool => ($row['side'] ?? null) === 'player' && ($row['effect_type'] ?? null) === 'damage'));
                $first[] = $rows[0]['amount'];
                $largest[] = max(array_column($rows, 'amount'));
                $burst[] = array_sum(array_column(array_filter($rows, static fn (array $row): bool => $row['round'] <= 10), 'amount'));
                $totals[] = $r->damageDealt;
            }
            $out['cases'][] = ['level' => $level, 'tree' => $tree, 'finesse_share' => $share, 'skills' => $p['active_skills'],
                'first_hit' => distribution($first), 'largest_hit' => distribution($largest), 'direct_damage_first_10' => distribution($burst), 'total_100' => distribution($totals),
                'burst_samples' => $burst];
        }
    }
}
foreach ($out['cases'] as &$row) {
    $pure = array_values(array_filter($out['cases'], static fn (array $candidate): bool => $candidate['level'] === $row['level'] && $candidate['tree'] === $row['tree'] && $candidate['finesse_share'] === 0))[0];
    $row['burst_threshold'] = (int) ceil($pure['direct_damage_first_10']['mean'] * 1.12);
    $row['burst_threshold_crossings'] = count(array_filter($row['burst_samples'], static fn (int $damage): bool => $damage >= $row['burst_threshold']));
}
unset($row);
foreach ($out['cases'] as &$row) {
    unset($row['burst_samples']);
}
unset($row);
file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
