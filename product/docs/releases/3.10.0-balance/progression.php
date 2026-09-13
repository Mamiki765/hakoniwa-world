<?php

use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Application\Underground\UndergroundTrialBalanceSimulator;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
[$script, $variant, $outputPath] = $argv;
$equipment = $app->make(UndergroundEquipmentCatalog::class);
$generator = $app->make(UndergroundRuntimeEquipmentGenerator::class);
$trial = $app->make(UndergroundTrialBalanceSimulator::class);
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$out = ['variant' => $variant, 'rounds' => 100, 'seeds' => 32, 'kingdom_solo' => [], 'trials' => []];
foreach ($variant === 'trials' ? [] : [['martial', 'martial_red', 'might', 'dagger'], ['guardianship', 'guardianship_blue', 'vitality', 'longsword'], ['miracle', 'blessing_green', 'spirit', 'crystal_staff']] as [$tree, $growth, $primary, $style]) {
    foreach ([40, 60, 90] as $level) {
        $itemLevel = min($level, (int) ($argv[5] ?? 90));
        $base = array_values(array_filter($inputs[$variant === 'old' ? 'old' : 'new'], static fn (array $row): bool => $row['tree'] === $tree && $row['level'] === 90))[0];
        $equipped = [];
        $accessoryStat = ($argv[7] ?? '') === 'solo' && $tree === 'martial' ? 'vitality' : $primary;
        foreach (['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'] as $index => $slot) {
            $category = str_starts_with($slot, 'accessory') ? 'accessory' : $slot;
            $definition = $generator->generate($itemLevel, 'obsidian_cavern', 'uncommon', $category, $category === 'weapon' ? $style : null, $category === 'accessory' ? $accessoryStat : null, 3100 + $index, 'release-3100-progression:'.$tree.':'.$slot);
            $equipped[] = ['slot' => $slot, 'definition' => $definition, 'catalog_identity' => $definition['generator_identity'], 'instance_identity' => $definition['instance_identity']];
        }
        $stp = array_fill_keys($rules::STATS, 0);
        $entitlement = $players->stpEntitlement($growth, $level);
        $stp[$primary] = (int) floor($entitlement * .8);
        $stp['vitality'] += $entitlement - $stp[$primary];
        if (($argv[7] ?? '') === 'solo' && $tree === 'martial') {
            $stp['might'] = intdiv($entitlement * 60, 100);
            $stp['vitality'] = $entitlement - $stp['might'];
        }
        if ($tree === 'guardianship') {
            $stp['might'] = intdiv($entitlement * (int) ($argv[6] ?? 40), 100);
            $stp['vitality'] = $entitlement - $stp['might'];
        }
        $p = $base['snapshot'];
        $p['stats'] = $players->currentStats($growth, $level, $stp);
        $p['equipment'] = $equipment->combatLoadout($equipped);
        unset($p['current_hp'], $p['awakening']);
        if ($variant !== 'old') {
            $skills = ($argv[7] ?? '') === 'solo' && $tree === 'martial'
                ? ['precision_cut', 'armor_break_strike', 'executioner_cut', 'counter_stance', 'renewing_guard']
                : standardSkills($tree, 100);
            $p = rebuildSnapshot($p, $skills, 100)['snapshot'];
        }
        $catalog = $players->explorationCatalog();
        $configuration = require $productRoot.'/config/underground-alpha-v1.php';
        $enemies = array_keys($configuration['exploration']['grounds']['shining_kingdom']['encounters']);
        if (isset($argv[3])) {
            $manifest = $catalog->manifest();
            foreach ($enemies as $enemy) {
                $manifest['enemies'][$enemy]['weapon_power'] = (int) round($manifest['enemies'][$enemy]['weapon_power'] * (int) $argv[3] / 10000);
                $manifest['enemies'][$enemy]['max_hp'] = (int) round($manifest['enemies'][$enemy]['max_hp'] * (int) $argv[4] / 10000);
            }
            $catalog = new AlphaV1BuildCatalog($manifest);
        }
        $rows = [];
        foreach ($enemies as $enemy) {
            $wins = 0;
            $rounds = [];
            for ($seed = 31000; $seed < 31032; $seed++) {
                $r = $model->fightPlayerSnapshot($catalog, $p, $enemy, $seed, 100, 300);
                $wins += $r->winner === 'player' ? 1 : 0;
                $rounds[] = $r->rounds;
            }
            $rows[] = ['enemy' => $enemy, 'wins' => $wins, 'rounds' => distribution($rounds)];
        }
        $out['kingdom_solo'][] = ['tree' => $tree, 'level' => $level, 'item_level' => $itemLevel, 'sp_budget' => 100, 'equipment_parts' => 5, 'accessory_stat' => $accessoryStat, 'allocated_stp' => $stp, 'active_skills' => $p['active_skills'], 'enemies' => $rows];
    }
}
foreach (isset($argv[3]) ? [] : [1 => [25, 30, 35], 2 => [40, 60, 90]] as $generation => $levels) {
    $manifest = json_decode(file_get_contents($productRoot.'/config/underground/balance/trial'.$generation.'-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['checkpoints'] = array_values(array_unique([...$manifest['checkpoints'], ...$levels]));
    foreach (['martial_red', 'guardianship_blue', 'blessing_green', 'free_black'] as $build) {
        foreach ($levels as $level) {
            $runs = [];
            for ($seed = 31000; $seed < 31032; $seed++) {
                $r = $trial->replay($manifest, $build.':lv'.$level.':heal2000', $seed)['result'];
                $runs[] = ['cleared' => $r['cleared'], 'rounds' => array_sum(array_column($r['battles'], 'rounds')), 'battles' => count($r['battles'])];
            }
            $out['trials'][] = ['generation' => $generation, 'growth' => $build, 'level' => $level,
                'wins' => count(array_filter($runs, static fn (array $r): bool => $r['cleared'])), 'rounds' => distribution(array_column($runs, 'rounds'))];
        }
    }
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
