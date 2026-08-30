<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\BuildCombatResult;

final class UndergroundAlphaV1BattleProjector
{
    /** @return array<string, mixed> */
    public function project(BuildCombatResult $result, AlphaV1BuildCatalog $catalog): array
    {
        $rounds = [];
        foreach ($result->actionLog as $row) {
            $round = (int) ($row['round'] ?? 0);
            if ($round < 1) {
                continue;
            }
            $rounds[$round] ??= ['round' => $round, 'actions' => [], 'end_state' => null];
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
                $rounds[$round]['actions'][] = [
                    'type' => 'decision',
                    'side' => $this->side((string) ($row['side'] ?? '')),
                    'label' => 'AI判断: '.$this->actionLabel($actionKey, $catalog),
                    'reason' => $this->reasonLabel((string) ($row['reason'] ?? '')),
                    'fallback' => (bool) ($row['fallback'] ?? false),
                    'mp_blocked' => (bool) ($row['mp_blocked'] ?? false),
                ];

                continue;
            }
            $rounds[$round]['actions'][] = $this->effect($row, $catalog);
        }
        ksort($rounds);

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
            ],
            'rounds' => array_values($rounds),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function effect(array $row, AlphaV1BuildCatalog $catalog): array
    {
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

        return [
            'type' => $type,
            'side' => $this->side((string) ($row['side'] ?? '')),
            'label' => $this->actionLabel($action, $catalog),
            'amount' => abs($amount),
            'critical' => (bool) ($row['critical'] ?? false),
            'evaded' => (bool) ($row['evaded'] ?? false),
            'guarded' => (bool) ($row['guarded'] ?? false),
            'parried' => (bool) ($row['parried'] ?? false),
            'barrier_absorbed' => (int) ($row['barrier_absorbed'] ?? 0),
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
            'statuses' => $statuses,
            'role_stacks' => [
                'fighting_spirit' => (int) ($roleStacks['fighting_spirit'] ?? 0),
                'grace' => (int) ($roleStacks['grace'] ?? 0),
            ],
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
        ];
        if (isset($plain[$action])) {
            return $plain[$action];
        }
        foreach (['apply_status:' => '付与: ', 'boss_status:' => '付与: ', 'status:' => '付与: ',
            'status_expired:' => '消滅: ', 'status_resisted:' => '抵抗: ',
            'periodic_damage:' => '継続damage: ', 'periodic_heal:' => '継続回復: '] as $prefix => $label) {
            if (str_starts_with($action, $prefix)) {
                return $label.$this->statusLabel(substr($action, strlen($prefix)), $catalog);
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

    private function reasonLabel(string $reason): string
    {
        if (preg_match('/^priority_rule_(\d+)$/D', $reason, $matches) === 1) {
            return '優先ルール '.((int) $matches[1] + 1);
        }

        return match ($reason) {
            'no_rule_matched' => '条件に合う優先ルールなし',
            'invalid_action' => '不正な指定を通常攻撃へfallback',
            default => '通常攻撃へfallback',
        };
    }

    private function side(string $side): string
    {
        return $side === 'player' ? '秘書' : ($side === 'enemy' ? '対戦相手' : 'system');
    }
}
