<?php

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
[$script, $outputPath] = $argv;
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR)['new'];
$healerCase = array_values(array_filter($inputs, static fn (array $row): bool => $row['tree'] === 'miracle' && $row['level'] === 90))[0];
$healer = rebuildSnapshot($healerCase['snapshot'], ['mending_prayer', 'regeneration', 'resurrection', 'crystal_cycle', 'lucid_dream'], 100)['snapshot'];
unset($healer['awakening']);
$healer['combatant_id'] = 'synthetic:healer';
$out = ['rounds' => 100, 'seeds' => 32, 'cases' => []];
foreach ($inputs as $case) {
    if ($case['level'] !== 90) {
        continue;
    }
    $p = rebuildSnapshot(standardRoleSnapshot($case), standardSkills($case['tree'], 100), 100)['snapshot'];
    unset($p['awakening']);
    $p['combatant_id'] = 'synthetic:'.$case['tree'];
    foreach ($case['tree'] === 'guardianship' ? [false, true] : [false] as $withHealer) {
        foreach ([1, 2, 4] as $count) {
            foreach ([1, 700, 1400] as $power) {
                $manifest = $players->explorationCatalog()->manifest();
                $manifest['enemies']['pressure'] = measurementEnemy(90, $power);
                $catalog = new AlphaV1BuildCatalog($manifest);
                $damage = [];
                $survived = 0;
                $rounds = [];
                $counters = [];
                $heals = [];
                $mp = [];
                $supportActions = [];
                $prepared = $p;
                if ($power === 1 && $case['tree'] === 'guardianship') {
                    $prepared['current_hp'] = (int) floor($prepared['current_hp'] * .94);
                }
                $party = $withHealer ? [$prepared, $healer] : [$prepared];
                for ($seed = 31000; $seed < 31032; $seed++) {
                    $r = $model->fightPartySnapshots($catalog, $party, array_fill(0, $count, 'pressure'), $seed, 100, 300);
                    $ownDamage = $withHealer ? array_sum(array_map(static fn (array $row): int => max(0, $row['amount']) + ($row['barrier_absorbed'] ?? 0), array_filter($r->actionLog,
                        static fn (array $row): bool => ($row['actor_id'] ?? null) === $p['combatant_id'] && in_array($row['effect_type'] ?? null, ['damage', 'counter'], true)))) : $r->metrics['damage_dealt'];
                    $damage[] = $ownDamage / 100;
                    $survived += $r->finalStates[$p['combatant_id']]['hp'] > 0 ? 1 : 0;
                    $rounds[] = $r->rounds;
                    $counters[] = count(array_filter($r->actionLog, static fn (array $row): bool => ($row['actor_id'] ?? null) === $p['combatant_id'] && ($row['effect_type'] ?? null) === 'counter'));
                    $heals[] = count(array_filter($r->actionLog, static fn (array $row): bool => ($row['actor_id'] ?? null) === $p['combatant_id'] && ($row['kind'] ?? null) === 'decision' && in_array($row['action_key'], ['renewing_guard', 'mending_prayer'], true)));
                    $mp[] = $r->finalStates[$p['combatant_id']]['mp'];
                    $supportActions[] = count(array_filter($r->actionLog, static fn (array $row): bool => ($row['actor_id'] ?? null) === 'synthetic:healer' && ($row['kind'] ?? null) === 'decision' && in_array($row['action_key'], ['mending_prayer', 'regeneration', 'resurrection', 'crystal_cycle', 'lucid_dream'], true)));
                }
                $out['cases'][] = ['tree' => $case['tree'], 'enemies' => $count, 'raw_power' => $power, 'external_healer' => $withHealer,
                    'start_hp_percent' => $power === 1 && $case['tree'] === 'guardianship' ? 94 : 100,
                    'dpr_per_100_scheduled_rounds' => distribution($damage), 'survived' => $survived,
                    'rounds' => distribution($rounds), 'counters' => distribution($counters), 'heals' => distribution($heals), 'mp' => distribution($mp), 'support_actions' => distribution($supportActions)];
            }
        }
    }
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
