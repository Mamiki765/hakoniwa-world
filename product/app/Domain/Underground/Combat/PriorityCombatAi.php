<?php

namespace App\Domain\Underground\Combat;

final class PriorityCombatAi
{
    /**
     * @return array{type: 'normal_attack'|'defend'|'skill', key: string|null, reason: string, fallback: bool, mp_blocked: bool}
     */
    public function select(
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
    ): array {
        $mpBlocked = false;
        foreach ($actor->aiRules as $index => $rule) {
            $conditions = $rule['conditions'] ?? [];
            if (is_array($conditions) && $this->otherwiseMatchingRuleIsBlockedByMp(
                $conditions,
                $actor,
                $enemy,
                $catalog,
                $round,
            )) {
                $mpBlocked = true;
            }
            if (! is_array($conditions) || ! $this->conditionsPass($conditions, $actor, $enemy, $catalog, $round)) {
                continue;
            }
            $action = $rule['action'] ?? null;
            if (! is_string($action)) {
                return $this->fallback('invalid_action', $mpBlocked);
            }
            if ($action === 'normal_attack' || $action === 'defend') {
                return [
                    'type' => $action,
                    'key' => null,
                    'reason' => 'priority_rule_'.$index,
                    'fallback' => false,
                    'mp_blocked' => $mpBlocked,
                ];
            }
            if (str_starts_with($action, 'skill:')) {
                $skillKey = substr($action, 6);
                if ($this->skillAvailable($actor, $catalog, $skillKey)) {
                    return [
                        'type' => 'skill',
                        'key' => $skillKey,
                        'reason' => 'priority_rule_'.$index,
                        'fallback' => false,
                        'mp_blocked' => $mpBlocked,
                    ];
                }

                $skill = $catalog->skill($skillKey);
                $blockedByMp = $actor->skillReady($skillKey)
                    && $actor->mp < $this->effectiveCost($actor, (int) ($skill['mp_cost'] ?? 0));

                return $this->fallback('matched_skill_unavailable', $mpBlocked || $blockedByMp);
            }

            return $this->fallback('invalid_action', $mpBlocked);
        }

        return $this->fallback('no_rule_matched', $mpBlocked);
    }

    public function skillAvailable(BuildCombatState $actor, AlphaV1BuildCatalog $catalog, string $skillKey): bool
    {
        if (! $actor->skillReady($skillKey)) {
            return false;
        }
        $skill = $catalog->skill($skillKey);

        return $actor->mp >= $this->effectiveCost($actor, (int) ($skill['mp_cost'] ?? 0));
    }

    public function effectiveCost(BuildCombatState $actor, int $cost): int
    {
        $reduction = min(
            AlphaV1CombatRules::MP_COST_REDUCTION_CAP_BPS,
            max(0, (int) ($actor->modifiers['mp_cost_reduction_bps'] ?? 0)),
        );

        return max(0, intdiv($cost * (10_000 - $reduction), 10_000));
    }

    /**
     * @param  list<mixed>  $conditions
     */
    private function conditionsPass(
        array $conditions,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
    ): bool {
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->conditionPasses($condition, $actor, $enemy, $catalog, $round)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $conditions */
    private function otherwiseMatchingRuleIsBlockedByMp(
        array $conditions,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
    ): bool {
        $blocked = false;
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return false;
            }
            if (($condition['type'] ?? null) !== 'skill_ready') {
                if (! $this->conditionPasses($condition, $actor, $enemy, $catalog, $round)) {
                    return false;
                }

                continue;
            }
            $skillKey = $condition['skill'] ?? null;
            if (! is_string($skillKey) || ! $actor->skillReady($skillKey)) {
                return false;
            }
            $skill = $catalog->skill($skillKey);
            if ($actor->mp >= $this->effectiveCost($actor, (int) ($skill['mp_cost'] ?? 0))) {
                return false;
            }
            $blocked = true;
        }

        return $blocked;
    }

    /** @param array<string, mixed> $condition */
    private function conditionPasses(
        array $condition,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
    ): bool {
        $type = $condition['type'] ?? null;
        $percent = $condition['percent'] ?? null;
        $status = $condition['status'] ?? null;
        $skill = $condition['skill'] ?? null;

        return match ($type) {
            'always' => true,
            'own_hp_lte' => is_int($percent) && $actor->hp * 100 <= $actor->maxHp * $percent,
            'own_hp_gte' => is_int($percent) && $actor->hp * 100 >= $actor->maxHp * $percent,
            'own_mp_lte' => is_int($percent) && $actor->mp * 100 <= AlphaV1CombatRules::MAX_MP * $percent,
            'own_mp_gte' => is_int($percent) && $actor->mp * 100 >= AlphaV1CombatRules::MAX_MP * $percent,
            'enemy_hp_lte' => is_int($percent) && $enemy->hp * 100 <= $enemy->maxHp * $percent,
            'self_has_status' => is_string($status) && $actor->hasStatus($status),
            'self_lacks_status' => is_string($status) && ! $actor->hasStatus($status),
            'enemy_has_status' => is_string($status) && $enemy->hasStatus($status),
            'enemy_lacks_status' => is_string($status) && ! $enemy->hasStatus($status),
            'status_stacks_gte' => is_string($status)
                && is_int($condition['stacks'] ?? null)
                && $actor->statusStacks($status) >= $condition['stacks'],
            'role_stacks_gte' => is_string($status)
                && is_int($condition['stacks'] ?? null)
                && $actor->roleStack($status) >= $condition['stacks'],
            'enemy_telegraph' => $enemy->hasStatus('telegraph'),
            'skill_ready' => is_string($skill) && $this->skillAvailable($actor, $catalog, $skill),
            'round_gte' => is_int($condition['round'] ?? null) && $round >= $condition['round'],
            'round_modulo' => is_int($condition['modulo'] ?? null)
                && $condition['modulo'] > 0
                && is_int($condition['equals'] ?? null)
                && $round % $condition['modulo'] === $condition['equals'],
            default => false,
        };
    }

    /** @return array{type: 'normal_attack', key: null, reason: string, fallback: true, mp_blocked: bool} */
    private function fallback(string $reason, bool $mpBlocked = false): array
    {
        return [
            'type' => 'normal_attack',
            'key' => null,
            'reason' => $reason,
            'fallback' => true,
            'mp_blocked' => $mpBlocked,
        ];
    }
}
