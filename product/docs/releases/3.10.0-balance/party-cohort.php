<?php

// Private input stays outside Git. Output contains anonymous profile/sample indices only.
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;

require __DIR__.'/readjustment-common.php';
require __DIR__.'/party-measurement.php';
[$script, $variant, $inputPath, $outputPath] = $argv;
$factor = (int) ($argv[4] ?? 10000);
$source = json_decode(file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
$configuration = require $productRoot.'/config/underground-alpha-v1.php';
$enemyKeys = array_keys($configuration['exploration']['grounds']['shining_kingdom']['encounters']);
$oldPowers = array_combine($enemyKeys, [1440, 1620, 2070, 1620, 1710, 1530, 1890, 1980, 1530, 1620, 2250, 1890, 2880, 2430]);
$manifest = $players->explorationCatalog()->manifest();
foreach ($oldPowers as $enemy => $power) {
    if ($factor !== 0) {
        $manifest['enemies'][$enemy]['weapon_power'] = (int) round($power * $factor / 10000);
    }
}
$catalog = new AlphaV1BuildCatalog($manifest);
$out = ['variant' => $variant, 'runtime_identity' => $rules::IDENTITY, 'weapon_bps_of_old' => $factor, 'cases' => [], 'skipped' => []];
foreach ($source as $index => $record) {
    if (! isset($record['snapshot']['party'])) {
        continue;
    }
    $s = $record['snapshot'];
    $party = [];
    $growths = [];
    $levels = [];
    try {
        foreach ($record['party_order'] as $id) {
            $member = $s['party']['members'][$id];
            $p = $member['player_snapshot'];
            $p['label'] = 'anonymous';
            $p['awakening']['message'] = 'anonymous';
            $p['stats'] = array_replace(array_fill_keys($rules::STATS, 0), $p['stats']);
            $p['equipment']['stats'] = array_replace(array_fill_keys($rules::STATS, 0), $p['equipment']['stats']);
            if ($variant === 'new') {
                $skills = array_map(static fn (string $key): string => $key === 'radiant_judgment' ? 'holy_lance' : $key, $p['active_skills']);
                $p = rebuildSnapshot($p, $skills, 100)['snapshot'];
            } else {
                $p['ai_rules'] = $ai->normalizeRules($p['ai_rules'], $catalog);
            }
            $party[] = $p;
            $growths[$id] = $member['growth_path_key'];
            $levels[] = $member['effective_combat_level'];
        }
        $ids = array_column($party, 'combatant_id');
        $tank = array_search('guardianship_blue', $growths, true) ?: $ids[0];
        $healer = array_search('blessing_green', $growths, true) ?: $ids[count($ids) - 1];
        $result = $model->fightPartySnapshots($catalog, $party, $s['encounter']['enemy_keys'], $record['private_seed'], 100, 300);
        $matched = $s['combat_rules_identity'] === $rules::IDENTITY && $result->rounds === $record['rounds'] && $result->winner === $s['summary']['winner'] && $result->metrics == $s['summary']['metrics'];
        foreach ($s['summary']['final_state'] as $id => $state) {
            $matched = $matched && $state['hp'] === $result->finalStates[$id]['hp'] && $state['mp'] === $result->finalStates[$id]['mp'];
        }
        $out['cases'][] = ['sample' => $index, 'profile' => $record['profile'], 'party_size' => count($party),
            'levels' => $levels, 'historical_identity' => $s['combat_rules_identity'], 'recorded_result' => $record['result'],
            'same_historical_identity' => $s['combat_rules_identity'] === $rules::IDENTITY, 'historical_metrics_match' => $matched,
            'tank_present' => in_array('guardianship_blue', $growths, true), 'healer_present' => in_array('blessing_green', $growths, true),
            'measurement' => partyMeasurement($result, $ids, $tank, $healer)];
    } catch (Throwable $error) {
        // Error text can contain identifiers: emit its class only into the public report.
        $out['skipped'][] = ['sample' => $index, 'error_class' => get_class($error)];
    }
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
