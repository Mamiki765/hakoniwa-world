<?php

// Reported damage is post-mitigation, excludes barrier absorption, includes overkill.
// Never write private combatant IDs or seeds: member positions are the output identity.
function partyMeasurement(object $result, array $ids, string $tank, string $healer): array
{
    $damage = array_fill_keys($ids, 0);
    $dead = array_fill_keys($ids, false);
    $aliveDuringBattle = [];
    foreach ($ids as $id) {
        $aliveDuringBattle[$id] = $result->initialStates[$id]['hp'] > 0;
    }
    $barrier = $damage;
    $actions = array_fill_keys(['mending_prayer', 'regeneration', 'resurrection', 'heart_of_mercy'], 0);
    $counter = 0;
    $minimumMp = 10000;
    foreach ($result->actionLog as $row) {
        $target = $row['target_id'] ?? '';
        if (isset($damage[$target])) {
            if (in_array($row['effect_type'] ?? '', ['damage', 'counter', 'bleed'], true)) {
                $damage[$target] += max(0, $row['amount']);
                $barrier[$target] += $row['barrier_absorbed'] ?? 0;
            }
            if (($row['revived'] ?? false) === true) {
                $dead[$target] = $dead[$target] || $aliveDuringBattle[$target];
                $aliveDuringBattle[$target] = true;
            }
        }
        if (($row['actor_id'] ?? '') === $tank && ($row['effect_type'] ?? '') === 'counter') {
            $counter++;
        }
        if (($row['actor_id'] ?? '') === $healer && ($row['kind'] ?? '') === 'decision' && isset($actions[$row['action_key']])) {
            $actions[$row['action_key']]++;
        }
        if (($row['kind'] ?? '') === 'round_end') {
            $minimumMp = min($minimumMp, $row['combatants'][$healer]['mp']);
        }
    }
    foreach ($ids as $id) {
        $dead[$id] = $dead[$id] || ($aliveDuringBattle[$id] && $result->finalStates[$id]['hp'] === 0);
    }

    return ['won' => $result->winner === 'player', 'rounds' => $result->rounds,
        'tank_died' => $dead[$tank], 'other_deaths' => count(array_filter(array_diff_key($dead, [$tank => true]))),
        'any_other_died' => count(array_filter(array_diff_key($dead, [$tank => true]))) > 0,
        'member_died' => array_values($dead), 'member_reported_damage' => array_values($damage), 'member_barrier_absorbed' => array_values($barrier),
        'healer_actions' => $actions, 'healer_final_mp' => $result->finalStates[$healer]['mp'],
        'healer_min_round_end_mp' => $minimumMp, 'tank_counters' => $counter,
        'invalid_resources' => count(array_filter($result->finalStates, static fn (array $s): bool => $s['hp'] < 0 || $s['hp'] > $s['max_hp'] || $s['mp'] < 0 || $s['mp'] > 10000))];
}

function partyAggregate(array $runs): array
{
    $out = ['runs' => count($runs), 'wins' => count(array_filter($runs, static fn (array $r): bool => $r['won'])),
        'tank_death_battles' => count(array_filter($runs, static fn (array $r): bool => $r['tank_died'])),
        'other_death_battles' => count(array_filter($runs, static fn (array $r): bool => $r['any_other_died'])),
        'invalid_resources' => array_sum(array_column($runs, 'invalid_resources'))];
    foreach (['rounds', 'healer_final_mp', 'healer_min_round_end_mp', 'tank_counters', 'other_deaths'] as $key) {
        $out[$key] = distribution(array_column($runs, $key));
    }
    foreach ($runs[0]['healer_actions'] as $key => $value) {
        $out['healer_actions'][$key] = distribution(array_map(static fn (array $r): int => $r['healer_actions'][$key], $runs));
    }
    foreach ($runs[0]['member_died'] as $index => $value) {
        $out['members'][] = ['position' => $index + 1,
            'death_battles' => count(array_filter($runs, static fn (array $r): bool => $r['member_died'][$index])),
            'reported_damage' => distribution(array_map(static fn (array $r): int => $r['member_reported_damage'][$index], $runs)),
            'barrier_absorbed' => distribution(array_map(static fn (array $r): int => $r['member_barrier_absorbed'][$index], $runs))];
    }

    return $out;
}
