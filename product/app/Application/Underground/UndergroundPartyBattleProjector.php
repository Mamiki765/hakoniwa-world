<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\PartyCombatResult;

/** Presentation-only projection for the party battle envelope (v3). */
final class UndergroundPartyBattleProjector
{
    public const PRESENTATION_LOG_VERSION = 3;

    /**
     * @param  array<string, array<string, mixed>>  $memberSnapshots  keyed by combatant ID
     * @return array{version: int, initial_state: array<string,mixed>, summary: array<string,mixed>, rounds: list<array<string,mixed>>, portrait_events: list<array<string,mixed>>}
     */
    public function project(PartyCombatResult $result, array $memberSnapshots, AlphaV1BuildCatalog $catalog): array
    {
        $initial = $this->states($result->initialStates, $memberSnapshots);
        $rounds = [];
        $awakeningRounds = [];
        $awakeningStates = [];
        foreach ($result->actionLog as $row) {
            $round = (int) ($row['round'] ?? 0);
            if ($round < 1) {
                continue;
            }
            $rounds[$round] ??= ['round' => $round, 'actions' => [], 'start_state' => null, 'end_state' => []];
            if (($row['kind'] ?? null) === 'round_end') {
                $rounds[$round]['end_state'] = $this->states(
                    is_array($row['combatants'] ?? null) ? $row['combatants'] : [],
                    $memberSnapshots,
                );

                continue;
            }
            $kind = is_string($row['kind'] ?? null) ? $row['kind'] : 'action';
            if (in_array($kind, ['awakening', 'awakening_technique'], true)
                && is_string($row['actor_id'] ?? null)) {
                $awakeningRounds[$row['actor_id']] ??= $round;
                if ($kind === 'awakening' && is_array($row['state'] ?? null)) {
                    $awakeningStates[$row['actor_id']] = $row['state'];
                }
            }
            $rounds[$round]['actions'][] = $this->action($row, $kind, $memberSnapshots, $catalog);
        }
        ksort($rounds);
        $previousBoundary = $initial;
        foreach ($rounds as &$round) {
            $round['start_state'] = $previousBoundary;
            $round['state_timing'] = $round['round'] === 1 ? 'battle_start' : 'previous_round_end';
            if ($round['end_state'] !== []) {
                $previousBoundary = $round['end_state'];
            }
        }
        unset($round);

        return [
            'version' => self::PRESENTATION_LOG_VERSION,
            'initial_state' => $initial,
            'summary' => [
                'winner' => $result->winner,
                'rounds' => $result->rounds,
                'metrics' => $result->metrics,
                'awakening' => $result->awakening,
                'final_state' => $this->states($result->finalStates, $memberSnapshots),
            ],
            'rounds' => array_values($rounds),
            'portrait_events' => $this->portraits($memberSnapshots, $awakeningRounds, $result->rounds, $initial, $result->finalStates, $awakeningStates),
        ];
    }

    /**
     * @param  array<string, mixed>  $states
     * @param  array<string, array<string, mixed>>  $members
     * @return array<string, array<string, mixed>>
     */
    private function states(array $states, array $members): array
    {
        $projected = [];
        foreach ($states as $id => $state) {
            if (! is_array($state)) {
                continue;
            }
            $combatantId = is_string($state['combatant_id'] ?? null) ? $state['combatant_id'] : (string) $id;
            $member = is_array($members[$combatantId] ?? null) ? $members[$combatantId] : [];
            $projected[$combatantId] = [
                'combatant_id' => $combatantId,
                'team' => $state['team'] ?? ($member['team'] ?? null),
                'label' => $state['label'] ?? ($member['label'] ?? $member['display_name'] ?? null),
                ...$state,
            ];
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function action(array $row, string $kind, array $members, AlphaV1BuildCatalog $catalog): array
    {
        $action = $row;
        $action['kind'] = $kind;
        $action['type'] = is_string($row['effect_type'] ?? null)
            ? $row['effect_type']
            : ($kind === 'decision' ? 'action' : ((int) ($row['amount'] ?? 0) < 0 ? 'recovery' : $kind));
        $action['side'] = is_string($row['team'] ?? null)
            ? $row['team']
            : (is_string($row['side'] ?? null) ? $row['side'] : 'system');
        $actionKey = $kind === 'decision' ? ($row['action_key'] ?? '') : ($row['action'] ?? '');
        $action['label'] = is_string($row['message'] ?? null)
            ? $row['message']
            : (new UndergroundAlphaV1BattleProjector)->actionLabel((string) $actionKey, $catalog);
        if (($row['reason'] ?? null) === 'outrage_chance') {
            $action['label'] = '無礼者！';
        }
        $actorId = is_string($row['actor_id'] ?? null) ? $row['actor_id'] : null;
        $targetId = is_string($row['target_id'] ?? null) ? $row['target_id'] : null;
        $action['actor_name'] = $actorId !== null && is_array($members[$actorId] ?? null)
            ? ($members[$actorId]['display_name'] ?? $members[$actorId]['label'] ?? null)
            : null;
        $action['target_name'] = $targetId !== null && is_array($members[$targetId] ?? null)
            ? ($members[$targetId]['display_name'] ?? $members[$targetId]['label'] ?? null)
            : null;
        if (in_array($action['type'], ['recovery', 'mp_recovery'], true)) {
            $action['amount'] = abs((int) ($row['amount'] ?? 0));
        }
        $action['important'] = in_array($kind, ['result', 'awakening', 'awakening_technique', 'revival'], true);
        if ($kind === 'awakening' && is_string($row['message'] ?? null)) {
            $action['lines'] = [$row['message']];
        }
        if ($kind === 'revival') {
            $action['label'] = '蘇生';
            $action['lines'] = [($action['target_name'] ?? '味方').'が復活した！'];
        }
        foreach (['actor_id', 'target_id', 'team'] as $key) {
            if (array_key_exists($key, $row) && ! is_string($row[$key]) && $row[$key] !== null) {
                $action[$key] = (string) $row[$key];
            }
        }
        if (isset($row['target_ids']) && is_array($row['target_ids'])) {
            $action['target_ids'] = array_values(array_map(static fn (mixed $id): string => (string) $id, $row['target_ids']));
        }

        return $action;
    }

    /**
     * @param  array<string, array<string, mixed>>  $members
     * @param  array<string, int>  $awakeningRounds
     * @param  array<string, array<string, mixed>>  $initial
     * @param  array<string, array<string, mixed>>  $final
     * @param  array<string, array<string, mixed>>  $awakenings
     * @return list<array<string, mixed>>
     */
    private function portraits(array $members, array $awakeningRounds, int $finalRound, array $initial, array $final, array $awakenings): array
    {
        $events = [];
        foreach ($members as $id => $member) {
            if (($member['team'] ?? 'player') !== 'player') {
                continue;
            }
            $refs = is_array($member['image_references'] ?? null) ? $member['image_references'] : [];
            $normal = $refs['normal'] ?? null;
            $awakened = $refs['awakening'] ?? $normal;
            $events[] = ['combatant_id' => $id, 'type' => 'start', 'round' => 1, 'state' => $initial[$id] ?? null, 'image_ref' => $normal, 'image_refs' => $this->refs($refs)];
            if (isset($awakeningRounds[$id])) {
                $events[] = ['combatant_id' => $id, 'type' => 'awakening', 'round' => $awakeningRounds[$id], 'state' => $awakenings[$id] ?? null, 'image_ref' => $awakened, 'image_refs' => $this->refs($refs)];
            }
            $events[] = ['combatant_id' => $id, 'type' => 'final', 'round' => $finalRound, 'state' => $final[$id] ?? null, 'image_ref' => ($final[$id]['awakened'] ?? false) ? $awakened : $normal, 'image_refs' => $this->refs($refs)];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $refs
     * @return array{compact: mixed, normal: mixed, awakening: mixed}
     */
    private function refs(array $refs): array
    {
        return [
            'compact' => $refs['compact'] ?? null,
            'normal' => $refs['normal'] ?? null,
            'awakening' => $refs['awakening'] ?? null,
        ];
    }
}
