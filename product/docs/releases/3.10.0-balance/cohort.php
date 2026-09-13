<?php

// Input contains private seeds. Only anonymous measurements are written out.
require __DIR__.'/readjustment-common.php';
[$script, $variant, $inputPath, $outputPath, $oldManifestPath] = $argv;
$source = json_decode(file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
$oldManifest = json_decode(file_get_contents($oldManifestPath), true, flags: JSON_THROW_ON_ERROR);
$oldNodes = [];
foreach ($oldManifest['skill_trees'] as $tree) {
    $oldNodes += $tree['nodes'];
}
$out = ['variant' => $variant, 'runtime_identity' => $rules::IDENTITY, 'cases' => [], 'skipped' => []];
foreach ($source as $index => $record) {
    if (! in_array($record['kind'], ['battle', 'trial_boss'], true)) {
        continue;
    }
    $s = $record['snapshot'];
    if (isset($s['party'])) {
        continue; // Party history is reported separately, never treated as solo.
    }
    $historical = $s['combat_rules_identity'] === $rules::IDENTITY;
    if ($variant === 'historical' && ! $historical) {
        continue;
    }
    $catalog = $record['activity_type'] === 'trial' ? $players->trialCatalog($record['activity_key']) : $players->explorationCatalog();
    $skills = $s['equipped_active_skills'];
    $allocations = [];
    $spent = 0;
    foreach ($s['acquired_skill_nodes'] as $key => $rank) {
        $spent += $oldNodes[$key]['point_cost_per_rank'] * $rank;
        $allocations[$key] = ['rank' => $rank, 'active_slot' => null];
    }
    foreach ($skills as $slot => $skill) {
        $nodeKey = $oldManifest['skills'][$skill]['node_key'];
        $allocations[$nodeKey]['active_slot'] = $slot + 1;
    }
    $budget = $record['activity_key'] === 'trial_01' ? 20 : ($record['activity_key'] === 'trial_02' ? 60 : 100);
    $budget = max($budget, $spent);
    $snapshot = [
        'key' => 'secretary_runtime', 'label' => $record['profile'],
        'stats' => array_replace(array_fill_keys($rules::STATS, 0), $s['progression_stats']),
        'equipment' => $s['equipment'], 'current_hp' => $s['current_hp_before'],
        'active_skills' => $skills, 'modifiers' => $s['effective_passive_modifiers'],
        'awakening' => ['unlocked' => $s['awakening']['unlocked'], 'gauge' => $s['awakening']['gauge_before'],
            'message' => '匿名', 'growth_path' => $s['growth_path_key'], 'technique_key' => $s['awakening']['technique']['key'] ?? null],
    ];
    $snapshot['equipment']['stats'] = array_replace(array_fill_keys($rules::STATS, 0), $snapshot['equipment']['stats']);
    try {
        if (in_array($variant, ['before', 'after'], true)) {
            $skills = array_map(static fn (string $key): string => $key === 'radiant_judgment' ? 'holy_lance' : $key, $skills);
            $rebuilt = rebuildSnapshot($snapshot, $skills, $budget);
            $snapshot = $rebuilt['snapshot'];
            $spent = $rebuilt['spent'];
        } else {
            $fallback = $players->playerSkillBuild($allocations, $snapshot['equipment']['weapon_style']);
            $snapshot['ai_rules'] = $ai->normalizeRules($s['ai']['rules'] ?? $ai->defaultRules($fallback['ai_rules'], $catalog), $catalog);
        }
        $r = $model->fightPlayerSnapshot($catalog, $snapshot, $record['encounter_key'], $record['private_seed'], 100,
            $players->growthPath($s['growth_path_key'])['natural_recovery']);
        $actual = $s['summary'];
        $out['cases'][] = ['sample' => $index, 'profile' => $record['profile'], 'kind' => $record['kind'],
            'window' => $record['window'] ?? 'boss_attempt', 'level' => $record['combat_level'],
            'content' => $record['activity_key'], 'enemy' => $record['encounter_key'], 'historical_identity' => $s['combat_rules_identity'],
            'same_historical_identity' => $historical, 'recorded_result' => $record['result'], 'recorded_rounds' => $record['rounds'],
            'result' => $r->winner, 'rounds' => $r->rounds, 'damage' => $r->damageDealt, 'hp' => $r->playerRemainingHp,
            'mp' => $r->finalMp, 'spent' => $spent, 'skills' => $skills,
            'historical_metrics_match' => $historical && $r->rounds === $record['rounds']
                && $r->damageDealt === $actual['damage_dealt'] && $r->playerRemainingHp === $actual['player_remaining_hp']
                && $r->finalMp === $actual['final_mp'],
            'abnormal' => $r->abnormalState];
    } catch (Throwable $error) {
        $out['skipped'][] = ['sample' => $index, 'kind' => $record['kind'], 'reason' => $error->getMessage()];
    }
}
file_put_contents($outputPath, json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
