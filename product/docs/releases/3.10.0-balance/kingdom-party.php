<?php

// Historical runs mount the exact old app/config and reuse this fixture generator.
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\UndergroundRandom;

require __DIR__.'/readjustment-common.php';
require __DIR__.'/party-measurement.php';
[$script, $variant, $outputPath] = $argv;
$level = (int) ($argv[3] ?? 90);
$itemLevel = (int) ($argv[4] ?? 75);
$factors = array_map('intval', explode(',', $argv[5] ?? '10000,25000'));
$seedCount = (int) ($argv[6] ?? 32);
$tankKit = $argv[7] ?? 'balanced';
$extended = ($argv[8] ?? '') === 'extended';
$historical = $variant !== 'new';
$inputs = json_decode(file_get_contents(__DIR__.'/inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$equipment = $app->make(UndergroundEquipmentCatalog::class);
$generator = $app->make(UndergroundRuntimeEquipmentGenerator::class);
$configuration = require $productRoot.'/config/underground-alpha-v1.php';
$enemyKeys = array_keys($configuration['exploration']['grounds']['shining_kingdom']['encounters']);
$oldPowers = array_combine($enemyKeys, [1440, 1620, 2070, 1620, 1710, 1530, 1890, 1980, 1530, 1620, 2250, 1890, 2880, 2430]);
$groups = [
    'lower_physical' => ['kingdom_gate_shield', 'kingdom_guard', 'kingdom_crossbow', 'kingdom_drummer'],
    'lower_mixed' => ['kingdom_ice_mage', 'kingdom_healer', 'kingdom_gate_shield', 'kingdom_guard'],
    'heavy' => ['kingdom_lancer', 'kingdom_musketeer', 'kingdom_executioner', 'kingdom_duelist'],
    'magic' => ['kingdom_fire_mage', 'kingdom_barrier_mage', 'kingdom_priest', 'kingdom_archmage'],
];
if ($extended) {
    $groups['strong'] = ['kingdom_duelist', 'kingdom_archmage', 'kingdom_duelist', 'kingdom_archmage'];
    $groups['rare'] = array_fill(0, 4, 'shining_court_noble');
    $groups['weighted_normal'] = []; // Conditional on a non-rare encounter, using runtime stream names.
}
$out = ['variant' => $variant, 'runtime_identity' => $rules::IDENTITY, 'level' => $level, 'item_level' => $itemLevel,
    'round_limit' => 100, 'seeds' => $seedCount, 'tank_kit' => $tankKit, 'groups' => $groups, 'builds' => [], 'cases' => []];
$templates = [];
foreach (['martial' => ['martial_red', 'might', 'dagger'], 'guardianship' => ['guardianship_blue', 'vitality', 'longsword'], 'miracle' => ['blessing_green', 'spirit', 'crystal_staff']] as $tree => [$growth, $primary, $style]) {
    $base = array_values(array_filter($inputs[$historical ? 'old' : 'new'], static fn (array $row): bool => $row['tree'] === $tree && $row['level'] === 90))[0]['snapshot'];
    $equipped = [];
    foreach (['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'] as $index => $slot) {
        $category = str_starts_with($slot, 'accessory') ? 'accessory' : $slot;
        $definition = $generator->generate($itemLevel, 'obsidian_cavern', 'uncommon', $category, $category === 'weapon' ? $style : null, $category === 'accessory' ? $primary : null, 3100 + $index, 'release-3100-party:'.$tree.':'.$slot);
        $equipped[] = ['slot' => $slot, 'definition' => $definition, 'catalog_identity' => $definition['generator_identity'], 'instance_identity' => $definition['instance_identity']];
    }
    $stp = array_fill_keys($rules::STATS, 0);
    $entitlement = $players->stpEntitlement($growth, $level);
    if ($tree === 'guardianship') {
        $stp['might'] = intdiv($entitlement * 40, 100);
        $stp['vitality'] = $entitlement - $stp['might'];
    } else {
        $stp[$primary] = intdiv($entitlement * 80, 100);
        $stp['vitality'] = $entitlement - $stp[$primary];
    }
    $base['stats'] = $players->currentStats($growth, $level, $stp);
    $base['equipment'] = $equipment->combatLoadout($equipped);
    $base['natural_recovery'] = $players->growthPath($growth)['natural_recovery'];
    unset($base['current_hp'], $base['awakening']);
    // v3's ally-heal flag reproduces v4's healer-owned capability.
    $base['party_healing_target_scope'] = $tree === 'miracle' ? 'single_ally' : 'self';
    $templates[$tree] = $base;
}
foreach ($historical ? [false] : [false, true] as $taunt) {
    $party = [];
    foreach (['leader' => 'martial', 'tank' => 'guardianship', 'healer' => 'miracle', 'area' => 'martial'] as $slot => $tree) {
        $snapshot = $templates[$tree];
        if ($historical) {
            if ($slot === 'tank') {
                foreach (['active_skills', 'modifiers', 'ai_rules', 'ai_mode'] as $field) {
                    if (isset($templates['martial'][$field])) {
                        $snapshot[$field] = $templates['martial'][$field];
                    }
                }
            }
            $spent = 100; // Old input fixture's prebuilt allocation, including ranked passives.
        } else {
            $skills = match ($slot) {
                'tank' => ! $taunt ? standardSkills('martial', 100) : ($tankKit === 'defensive'
                    ? ['counter_stance', 'renewing_guard', 'fortress', 'sundering_shield', 'protective_oath']
                    : ['rallying_cry', 'counter_stance', 'renewing_guard', 'bulwark_strike', 'sundering_shield']),
                'healer' => ['mending_prayer', 'regeneration', 'resurrection', 'heart_of_mercy', 'crystal_cycle'],
                'area' => ['precision_cut', 'whirlwind', 'executioner_cut', 'quick_stab', 'armor_break_strike'],
                default => standardSkills('martial', 100),
            };
            $rebuilt = rebuildSnapshot($snapshot, $skills, 100);
            $snapshot = $rebuilt['snapshot'];
            $spent = $rebuilt['spent'];
        }
        $snapshot['combatant_id'] = 'fixture:'.$slot;
        $party[$slot] = $snapshot;
        $out['builds'][($taunt ? 'taunt:' : 'no_taunt:').$slot] = ['spent' => $spent, 'skills' => $snapshot['active_skills'],
            'stats' => $snapshot['stats'], 'equipment' => $snapshot['equipment']];
    }
    foreach ([2, 3, 4] as $size) {
        $members = $size === 2 ? [$party['tank'], $party['healer']] : array_slice(array_values($party), 0, $size);
        foreach ($factors as $factor) {
            $manifest = $players->explorationCatalog()->manifest();
            foreach ($oldPowers as $enemy => $power) {
                if ($factor !== 0) { // 0 measures the current authored catalog, including group differences.
                    $manifest['enemies'][$enemy]['weapon_power'] = (int) round($power * $factor / 10000);
                }
            }
            $catalog = new AlphaV1BuildCatalog($manifest);
            foreach ($groups as $group => $enemies) {
                $runs = [];
                for ($seed = 31000; $seed < 31000 + $seedCount; $seed++) {
                    $selected = array_slice($enemies, 0, $size);
                    if ($group === 'weighted_normal') {
                        $random = new UndergroundRandom($seed);
                        for ($index = 0; $index < $players->explorationEnemyCountForPartySize('shining_kingdom', $size); $index++) {
                            $stream = 'runtime:encounter:shining_kingdom'.($index === 0 ? '' : ':slot:'.$index);
                            $selected[] = $players->weightedExplorationEncounter($random->integer($stream, 1, 10000), 'shining_kingdom');
                        }
                    }
                    $result = $model->fightPartySnapshots($catalog, $members, $selected, $seed, 100, 300);
                    $runs[] = partyMeasurement($result, array_column($members, 'combatant_id'), 'fixture:tank', 'fixture:healer');
                }
                $out['cases'][] = ['party_size' => $size, 'group' => $group, 'weapon_bps_of_old' => $factor, 'taunt_kit' => $taunt,
                    'summary' => partyAggregate($runs)];
            }
        }
    }
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
