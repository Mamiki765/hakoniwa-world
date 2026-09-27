<?php

use App\Application\Underground\AtomicUndergroundPartyCombat;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/benchmark.php';

$partyCombat = $app->make(AtomicUndergroundPartyCombat::class);
$level = (int) ($argv[1] ?? 650);
$seedStart = (int) ($argv[2] ?? 31000);
$seedCount = (int) ($argv[3] ?? 32);
$buildKeys = [
    'martial_100', 'martial_100_sustain', 'martial_100_bleed_sustain',
    'guardian_100_standard', 'miracle_100_two_attack',
    'martial_140_dispel_sustain', 'martial_140_wound_sustain',
    'guardian_140_cover', 'guardian_140_barrier',
    'miracle_140_group', 'miracle_140_hybrid',
];
$report = [];
foreach ($buildKeys as $key) {
    $spec = $reference[$key];
    $base = $source['builds'][$spec['base']];
    if (isset($base['inherits'])) {
        $base = array_replace($source['builds'][$base['inherits']], $base);
    }
    $contexts = [];
    foreach (['entry', 'after_five', 'before_boss'] as $stage) {
        $measured = measuredBuild($source, $key, $spec, $level, $players, $equipment, $generator, $stage);
        $definition = $measured['definition'];
        $manifest = $definition['catalog']->manifest();
        foreach (['skills', 'statuses', 'enemies'] as $section) {
            foreach ($source[$section] as $name => $value) {
                $manifest[$section][$name] = $value;
            }
        }
        $contexts[$stage] = [
            'catalog' => new AlphaV1BuildCatalog($manifest),
            'snapshot' => $definition['player_snapshot'],
            'max_hp' => $definition['max_hp'],
        ];
    }
    $clears = $bossReached = $totalRounds = $totalHeal = $totalPrevented = 0;
    $actionUsage = [];
    for ($seed = $seedStart; $seed < $seedStart + $seedCount; $seed++) {
        $hp = $contexts['entry']['max_hp'];
        $gauge = 0;
        $cleared = true;
        foreach ($source['battle_sequence'] as $offset => $encounter) {
            $battleIndex = $offset + 1;
            $stage = $battleIndex < 6 ? 'entry' : ($battleIndex < 10 ? 'after_five' : 'before_boss');
            $context = $contexts[$stage];
            $snapshot = $context['snapshot'];
            $snapshot['combatant_id'] = 'player:1';
            $snapshot['current_hp'] = $hp;
            $snapshot['awakening'] = [
                'unlocked' => true, 'gauge' => $gauge,
                'message' => '代表buildの覚醒が発動した――！',
                'growth_path' => $base['growth_path'],
                'technique_key' => $base['awakening_technique_key'],
            ];
            $enemyKeys = $source['enemy_parties'][$encounter] ?? [$encounter];
            $battleSeed = (int) (hexdec(substr(hash('sha256', $source['trial_identity']."|{$seed}|{$battleIndex}"), 0, 8)) & 0x7FFFFFFF);
            $result = $partyCombat->fight($context['catalog'], [$snapshot], $enemyKeys,
                $battleSeed, $source['max_rounds'], $source['mp_natural_recovery']);
            $totalRounds += $result->rounds;
            $totalHeal += (int) ($result->metrics['effective_healing'] ?? 0);
            $totalPrevented += (int) ($result->metrics['damage_prevented'] ?? 0);
            foreach ($result->actionLog as $row) {
                if (($row['kind'] ?? null) === 'decision' && ($row['team'] ?? null) === 'player') {
                    $action = $row['action_key'];
                    $actionUsage[$action] = ($actionUsage[$action] ?? 0) + 1;
                }
            }
            if ($battleIndex === 10) {
                $bossReached++;
            }
            if ($result->winner !== 'player') {
                $cleared = false;
                break;
            }
            $hp = (int) $result->finalStates['player:1']['hp'];
            $gauge = $result->awakening['player:1']['gauge_after'];
            if ($battleIndex < 10) {
                $hp = min($context['max_hp'], $hp + intdiv($context['max_hp'] * 3000, 10_000));
            }
        }
        $clears += $cleared ? 1 : 0;
    }
    $report[$key] = [
        'sp' => $measured['spent'], 'clears' => $clears, 'boss_reached' => $bossReached,
        'rounds_mean' => $totalRounds / $seedCount,
        'healing_mean' => $totalHeal / $seedCount,
        'prevented_mean' => $totalPrevented / $seedCount,
        'actions' => $actionUsage,
    ];
}
echo json_encode(['level' => $level, 'seed_start' => $seedStart, 'seed_count' => $seedCount,
    'interbattle_healing_bps' => 3000, 'results' => $report],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
