<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\UndergroundAwakening;

final class UndergroundAlphaV1BattleProjector
{
    public const PRESENTATION_LOG_VERSION = 2;

    /** @return array<string, mixed> */
    public function project(
        BuildCombatResult $result,
        AlphaV1BuildCatalog $catalog,
        string $playerDisplayName = '秘書',
        string $enemyDisplayName = '対戦相手',
    ): array {
        $initialState = $this->statePair($result->initialState, $catalog);
        $rounds = [];
        foreach ($result->actionLog as $row) {
            $round = (int) ($row['round'] ?? 0);
            if ($round < 1) {
                continue;
            }
            $rounds[$round] ??= ['round' => $round, 'actions' => [], 'start_state' => null, 'end_state' => null];
            $kind = $row['kind'] ?? 'effect';
            if ($kind === 'round_end') {
                $rounds[$round]['end_state'] = [
                    'player' => $this->state($row['player'] ?? null, $catalog),
                    'enemy' => $this->state($row['enemy'] ?? null, $catalog),
                ];

                continue;
            }
            if ($kind === 'decision') {
                $actionKey = is_string($row['action_key'] ?? null) ? $row['action_key'] : '';
                $side = (string) ($row['side'] ?? '');
                $rounds[$round]['actions'][] = [
                    'type' => 'action',
                    'side' => $this->side($side),
                    'actor_name' => $this->displayName($side, $playerDisplayName, $enemyDisplayName),
                    'target_name' => null,
                    'label' => $this->actionLabel($actionKey, $catalog),
                ];

                continue;
            }
            if ($kind === 'awakening') {
                $message = is_string($row['message'] ?? null) && $row['message'] !== ''
                    ? $row['message']
                    : "魔力が{$playerDisplayName}の全身を駆け巡る――！";
                $rounds[$round]['actions'][] = [
                    'type' => 'awakening',
                    'side' => '秘書',
                    'actor_name' => $playerDisplayName,
                    'target_name' => $playerDisplayName,
                    'label' => '覚醒',
                    'lines' => [
                        $message,
                        "{$playerDisplayName}は覚醒した！",
                        'HP/MPが全回復した！',
                        '生命・武力・技巧・精神・敏捷が30%上昇した！',
                    ],
                    'amount' => 0,
                ];

                continue;
            }
            if ($kind === 'awakening_technique') {
                $name = is_string($row['message'] ?? null) ? $row['message'] : '覚醒技';
                $rounds[$round]['actions'][] = [
                    'type' => 'awakening_technique',
                    'side' => '秘書',
                    'actor_name' => $playerDisplayName,
                    'target_name' => $this->displayName(
                        is_string($row['target_side'] ?? null) ? $row['target_side'] : 'enemy',
                        $playerDisplayName,
                        $enemyDisplayName,
                    ),
                    'label' => $name,
                    'lines' => ["{$playerDisplayName}は覚醒技「{$name}」を発動した！"],
                    'amount' => 0,
                ];

                continue;
            }
            $rounds[$round]['actions'][] = $this->effect(
                $row,
                $catalog,
                $playerDisplayName,
                $enemyDisplayName,
            );
        }
        ksort($rounds);
        $nextStartState = $initialState;
        foreach ($rounds as &$round) {
            $round['start_state'] = $nextStartState;
            if (is_array($round['end_state'])) {
                $nextStartState = $round['end_state'];
            }
        }
        unset($round);

        return [
            'summary' => [
                'result' => $result->winner === 'player' ? 'victory' : ($result->winner === 'enemy' ? 'defeat' : 'stalemate'),
                'rounds' => $result->rounds,
                'player_remaining_hp' => $result->playerRemainingHp,
                'enemy_remaining_hp' => $result->enemyRemainingHp,
                'final_mp' => $result->finalMp,
                'damage_dealt' => $result->damageDealt,
                'damage_received' => $result->damageReceived,
                'effective_healing' => $result->effectiveHealing,
                'damage_prevented' => $result->damagePrevented,
                'mp_spent' => $result->mpSpent,
                'mp_natural_recovery' => $result->mpNaturalRecovery,
                'mp_skill_recovery' => $result->mpSkillRecovery,
                'skill_unavailable_due_to_mp' => $result->skillUnavailableDueToMp,
                'awakening_triggered' => $result->awakening['triggered'],
                'awakening_technique_used' => (bool) ($result->awakening['technique']['used'] ?? false),
            ],
            'initial_state' => $initialState,
            'rounds' => array_values($rounds),
        ];
    }

    /** @return array{player: array<string, mixed>, enemy: array<string, mixed>}|null */
    private function statePair(mixed $value, AlphaV1BuildCatalog $catalog): ?array
    {
        if (! is_array($value)
            || ! is_array($value['player'] ?? null)
            || ! is_array($value['enemy'] ?? null)) {
            return null;
        }

        return [
            'player' => $this->state($value['player'], $catalog),
            'enemy' => $this->state($value['enemy'], $catalog),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function effect(
        array $row,
        AlphaV1BuildCatalog $catalog,
        string $playerDisplayName,
        string $enemyDisplayName,
    ): array {
        $action = is_string($row['action'] ?? null) ? $row['action'] : '';
        $amount = (int) ($row['amount'] ?? 0);
        $configuredType = $row['effect_type'] ?? null;
        $type = is_string($configuredType) && $configuredType !== ''
            ? $configuredType
            : ($amount > 0 ? 'damage' : ($amount < 0 ? 'recovery' : 'state'));
        if (str_starts_with($action, 'apply_status:')
            || str_starts_with($action, 'status:')
            || str_starts_with($action, 'boss_status:')) {
            $type = 'status_applied';
        } elseif (str_starts_with($action, 'status_expired:')) {
            $type = 'status_expired';
        } elseif (str_starts_with($action, 'status_resisted:')) {
            $type = 'status_resisted';
        } elseif ($action === 'counter') {
            $type = 'counter';
        } elseif ($action === 'defend') {
            $type = 'guard';
        }

        $side = (string) ($row['side'] ?? '');
        $targetSide = is_string($row['target_side'] ?? null) ? $row['target_side'] : $side;
        $message = is_string($row['message'] ?? null) && $row['message'] !== ''
            ? $row['message']
            : null;

        return [
            'type' => $type,
            'side' => $this->side($side),
            'actor_name' => $this->displayName($side, $playerDisplayName, $enemyDisplayName),
            'target_name' => $this->displayName($targetSide, $playerDisplayName, $enemyDisplayName),
            'label' => $type === 'phase_transition' && $message !== null
                ? $message
                : $this->actionLabel($action, $catalog),
            'amount' => abs($amount),
            'critical' => (bool) ($row['critical'] ?? false),
            'evaded' => (bool) ($row['evaded'] ?? false),
            'guarded' => (bool) ($row['guarded'] ?? false),
            'parried' => (bool) ($row['parried'] ?? false),
            'barrier_absorbed' => (int) ($row['barrier_absorbed'] ?? 0),
            'complete_guarded' => (bool) ($row['complete_guarded'] ?? false),
            'agility_combo_hits' => is_int($row['agility_combo_hits'] ?? null)
                ? $row['agility_combo_hits']
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function state(mixed $value, AlphaV1BuildCatalog $catalog): array
    {
        $value = is_array($value) ? $value : [];
        $statuses = [];
        foreach (($value['statuses'] ?? []) as $status) {
            if (! is_array($status) || ! is_string($status['key'] ?? null)) {
                continue;
            }
            $statuses[] = [
                'label' => $this->statusLabel($status['key'], $catalog),
                'remaining' => (int) ($status['remaining'] ?? 0),
                'stacks' => (int) ($status['stacks'] ?? 0),
            ];
        }
        $roleStacks = is_array($value['role_stacks'] ?? null) ? $value['role_stacks'] : [];

        return [
            'hp' => (int) ($value['hp'] ?? 0),
            'max_hp' => (int) ($value['max_hp'] ?? 0),
            'mp' => (int) ($value['mp'] ?? 0),
            'barrier' => (int) ($value['barrier'] ?? 0),
            'taunt' => is_array($value['taunt'] ?? null) ? [
                'label' => '挑発',
                'source_side' => $this->side((string) ($value['taunt']['source_side'] ?? '')),
                'source_key' => is_string($value['taunt']['source_key'] ?? null)
                    ? $value['taunt']['source_key']
                    : null,
            ] : null,
            'statuses' => $statuses,
            'role_stacks' => [
                'fighting_spirit' => (int) ($roleStacks['fighting_spirit'] ?? 0),
                'grace' => (int) ($roleStacks['grace'] ?? 0),
            ],
            'awakened' => (bool) ($value['awakened'] ?? false),
            'awakening_technique_used' => (bool) ($value['awakening_technique_used'] ?? false),
            'awakening_guard_rounds_remaining' => (int) ($value['awakening_guard_rounds_remaining'] ?? 0),
            'awakening_lifesteal_rounds_remaining' => (int) ($value['awakening_lifesteal_rounds_remaining'] ?? 0),
            'awakening_unlocked' => (bool) ($value['awakening_unlocked'] ?? false),
            'awakening_gauge' => (int) ($value['awakening_gauge'] ?? 0),
            'awakening_gauge_max' => (int) ($value['awakening_gauge_max'] ?? UndergroundAwakening::GAUGE_MAX),
        ];
    }

    private function actionLabel(string $action, AlphaV1BuildCatalog $catalog): string
    {
        $plain = [
            'normal_attack' => '通常攻撃',
            'defend' => '防御',
            'counter' => '反撃',
            'action_impaired' => '行動不能',
            'self_regeneration' => '自己再生',
            'lifesteal' => '吸血',
            'complete_guard' => '完全防御',
            'mp_cost' => 'MP消費',
            'mp_recovery' => 'MP回復',
            'taunt' => '挑発',
            'awakening' => '覚醒',
            'decisive_heavenrend' => '天断一閃',
            'absolute_aegis' => '絶対護界',
            'absolute_aegis_expired' => '絶対護界終了',
            'life_requiem' => '生命讃歌',
            'limitless_reprise' => '無窮再演',
            'shura_bloodline' => '修羅の血脈',
            'shura_bloodline_lifesteal' => '修羅の血脈',
            'shura_bloodline_expired' => '修羅の血脈終了',
            'fortress_strike' => '城塞撃',
            'fortress_strike_guard' => '城塞撃の防御態勢',
            'judgment_light' => '裁きの天光',
            'formless_strike' => '無相の一撃',
        ];
        if (isset($plain[$action])) {
            return $plain[$action];
        }
        foreach (['apply_status:' => '付与: ', 'boss_status:' => '付与: ', 'status:' => '付与: ',
            'status_expired:' => '消滅: ', 'status_resisted:' => '抵抗: ',
            'periodic_damage:' => '継続ダメージ: ', 'periodic_heal:' => '継続回復: ',
            'role_stack_gain:' => '増加: ', 'role_stack_spent:' => '消費: '] as $prefix => $label) {
            if (str_starts_with($action, $prefix)) {
                $key = substr($action, strlen($prefix));
                $roleLabel = match ($key) {
                    'fighting_spirit' => '闘志',
                    'grace' => '恩寵',
                    default => null,
                };

                return $label.($roleLabel ?? $this->statusLabel($key, $catalog));
            }
        }
        try {
            $skill = $catalog->skill($action);
            $label = $skill['label'] ?? null;

            return is_string($label) && $label !== '' ? $label : 'Skill';
        } catch (\InvalidArgumentException) {
            return '戦闘効果';
        }
    }

    private function statusLabel(string $key, AlphaV1BuildCatalog $catalog): string
    {
        try {
            $status = $catalog->status($key);
            $label = $status['label'] ?? null;

            return is_string($label) && $label !== '' ? $label : '状態効果';
        } catch (\InvalidArgumentException) {
            return '状態効果';
        }
    }

    private function side(string $side): string
    {
        return $side === 'player' ? '秘書' : ($side === 'enemy' ? '対戦相手' : 'system');
    }

    private function displayName(string $side, string $playerDisplayName, string $enemyDisplayName): string
    {
        return $side === 'player' ? $playerDisplayName : ($side === 'enemy' ? $enemyDisplayName : '戦闘');
    }
}
