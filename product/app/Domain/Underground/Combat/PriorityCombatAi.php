<?php

namespace App\Domain\Underground\Combat;

final class PriorityCombatAi
{
    public function __construct(
        private readonly PriorityCombatAiConfiguration $configuration = new PriorityCombatAiConfiguration,
    ) {}

    /**
     * @param  list<BuildCombatState>  $allies
     * @param  list<BuildCombatState>  $enemies
     * @return array{type: 'normal_attack'|'defend'|'skill'|'awakening', key: string|null, target_id: string|null, reason: string, fallback: bool, mp_blocked: bool, next_rule_index: int}
     */
    public function select(
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
        int $startRuleIndex = 0,
        array $allies = [],
        array $enemies = [],
    ): array {
        if ($startRuleIndex < 0 || $startRuleIndex > count($actor->aiRules)) {
            throw new \InvalidArgumentException('Underground AI rule cursor is invalid.');
        }
        $mpBlocked = false;
        $index = $startRuleIndex;
        while ($index < count($actor->aiRules)) {
            $rule = $actor->aiRules[$index];
            $action = $rule['action'] ?? null;
            if (! is_string($action)) {
                return $this->fallback($actor, $catalog, 'invalid_action', $mpBlocked);
            }
            $targetSelector = $rule['target'] ?? null;
            $ruleTargets = $this->ruleTargets(
                $targetSelector,
                $action,
                $actor,
                $enemy,
                $allies,
                $enemies,
            );
            if ($ruleTargets === []) {
                $index++;

                continue;
            }
            $conditions = $rule['conditions'] ?? [];
            $ruleTarget = null;
            if (is_array($conditions)) {
                foreach ($ruleTargets as $candidate) {
                    $conditionEnemy = $targetSelector === 'untaunted_enemy' ? $candidate : $enemy;
                    if ($this->otherwiseMatchingRuleIsBlockedByMp(
                        $conditions,
                        $actor,
                        $conditionEnemy,
                        $catalog,
                        $round,
                        $allies,
                    )) {
                        $mpBlocked = true;
                    }
                    if ($this->conditionsPass($conditions, $actor, $conditionEnemy, $catalog, $round, $allies)) {
                        $ruleTarget = $candidate;
                        break;
                    }
                }
            }
            if (! $ruleTarget instanceof BuildCombatState) {
                $index++;

                continue;
            }
            if ($action === 'jump') {
                $jumpTo = $rule['jump_to'] ?? null;
                if (! is_int($jumpTo) || $jumpTo <= $index + 1 || $jumpTo > count($actor->aiRules)) {
                    return $this->fallback($actor, $catalog, 'invalid_jump', $mpBlocked);
                }
                $index = $jumpTo - 1;

                continue;
            }
            if ($action === 'normal_attack' || $action === 'defend') {
                return [
                    'type' => $action,
                    'key' => null,
                    'target_id' => $ruleTarget->combatantId,
                    'reason' => 'priority_rule_'.$index,
                    'fallback' => false,
                    'mp_blocked' => $mpBlocked,
                    'next_rule_index' => $index + 1,
                ];
            }
            if ($action === 'awakening') {
                if ($actor->side === 'player'
                    && $actor->awakeningUnlocked
                    && ! $actor->awakened
                    && $actor->awakeningGauge >= UndergroundAwakening::GAUGE_MAX) {
                    return [
                        'type' => 'awakening',
                        'key' => null,
                        'target_id' => $ruleTarget->combatantId,
                        'reason' => 'priority_rule_'.$index,
                        'fallback' => false,
                        'mp_blocked' => $mpBlocked,
                        'next_rule_index' => $index + 1,
                    ];
                }
                $index++;

                continue;
            }
            if (str_starts_with($action, 'skill:')) {
                $skillKey = substr($action, 6);
                if ($this->skillAvailable($actor, $catalog, $skillKey)) {
                    return [
                        'type' => 'skill',
                        'key' => $skillKey,
                        'target_id' => $ruleTarget->combatantId,
                        'reason' => 'priority_rule_'.$index,
                        'fallback' => false,
                        'mp_blocked' => $mpBlocked,
                        'next_rule_index' => $index + 1,
                    ];
                }

                $skill = $catalog->skill($skillKey);
                $blockedByMp = $actor->skillReady($skillKey)
                    && $actor->mp < $this->effectiveCost($actor, (int) ($skill['mp_cost'] ?? 0));
                $mpBlocked = $mpBlocked || $blockedByMp;

                $index++;

                continue;
            }

            return $this->fallback($actor, $catalog, 'invalid_action', $mpBlocked);
        }

        return $this->fallback($actor, $catalog, 'no_rule_matched', $mpBlocked);
    }

    /**
     * @param  list<BuildCombatState>  $allies
     * @param  list<BuildCombatState>  $enemies
     * @return list<BuildCombatState>
     */
    private function ruleTargets(
        mixed $selector,
        string $action,
        BuildCombatState $actor,
        BuildCombatState $currentEnemy,
        array $allies,
        array $enemies,
    ): array {
        if ($selector === null) {
            return [$currentEnemy];
        }
        if ($selector === 'lowest_hp_ally') {
            if ($action !== 'skill:mending_prayer'
                || ($actor->flags['party_healing_target_scope'] ?? 'self') !== 'single_ally') {
                return [];
            }
            $target = $this->lowestHpRatioTarget($allies !== [] ? $allies : [$actor]);

            return $target instanceof BuildCombatState ? [$target] : [];
        }
        if ($selector === 'untaunted_enemy') {
            $candidates = [];
            foreach ($enemies !== [] ? $enemies : [$currentEnemy] as $enemy) {
                if ($enemy->alive() && ! $this->hasEffectiveTaunt($enemy, $allies !== [] ? $allies : [$actor])) {
                    $candidates[] = $enemy;
                }
            }

            return $candidates;
        }

        return [];
    }

    /** @param list<BuildCombatState> $targets */
    private function lowestHpRatioTarget(array $targets): ?BuildCombatState
    {
        $selected = null;
        foreach ($targets as $target) {
            if (! $target->alive()) {
                continue;
            }
            if (! $selected instanceof BuildCombatState
                || $target->hp * $selected->maxHp < $selected->hp * $target->maxHp) {
                $selected = $target;
            }
        }

        return $selected;
    }

    /** @param list<BuildCombatState> $allies */
    private function hasEffectiveTaunt(BuildCombatState $enemy, array $allies): bool
    {
        $sourceCombatantId = $enemy->taunt['source_combatant_id'] ?? null;
        if (is_string($sourceCombatantId)) {
            foreach ($allies as $ally) {
                if ($ally->combatantId === $sourceCombatantId) {
                    return $ally->alive();
                }
            }

            return false;
        }

        $sourceKey = $enemy->taunt['source_key'] ?? null;
        if (! is_string($sourceKey)) {
            return false;
        }
        foreach ($allies as $ally) {
            if ($ally->key === $sourceKey && $ally->alive()) {
                return true;
            }
        }

        return false;
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
     * @param  list<BuildCombatState>  $allies
     */
    private function conditionsPass(
        array $conditions,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
        array $allies = [],
    ): bool {
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->conditionPasses($condition, $actor, $enemy, $catalog, $round, $allies)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $conditions
     * @param  list<BuildCombatState>  $allies
     */
    private function otherwiseMatchingRuleIsBlockedByMp(
        array $conditions,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
        array $allies = [],
    ): bool {
        $blocked = false;
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return false;
            }
            if (($condition['type'] ?? null) !== 'skill_ready') {
                if (! $this->conditionPasses($condition, $actor, $enemy, $catalog, $round, $allies)) {
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

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<BuildCombatState>  $allies
     */
    private function conditionPasses(
        array $condition,
        BuildCombatState $actor,
        BuildCombatState $enemy,
        AlphaV1BuildCatalog $catalog,
        int $round,
        array $allies = [],
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
            'ally_hp_lte' => is_int($percent) && $this->allyPercentageAtOrBelow($allies, $percent),
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

    /** @param  list<BuildCombatState>  $allies */
    private function allyPercentageAtOrBelow(array $allies, int $percent): bool
    {
        foreach ($allies as $ally) {
            if (! $ally->alive()) {
                continue;
            }

            if ($ally->hp * 100 <= $ally->maxHp * $percent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{type: 'normal_attack'|'skill', key: string|null, target_id: null, reason: string, fallback: true, mp_blocked: bool, next_rule_index: int}
     */
    private function fallback(
        BuildCombatState $actor,
        AlphaV1BuildCatalog $catalog,
        string $reason,
        bool $mpBlocked = false,
    ): array {
        if ($actor->side === 'player') {
            foreach ($this->configuration->playerSkills($catalog) as $skillEntry) {
                $skillKey = $skillEntry['key'];
                $skill = $catalog->skill($skillKey);
                if (! in_array('damage', array_column($skill['effects'], 'type'), true)
                    || ! in_array($skillKey, $actor->skills, true)) {
                    continue;
                }
                if ($this->skillAvailable($actor, $catalog, $skillKey)) {
                    return [
                        'type' => 'skill',
                        'key' => $skillKey,
                        'target_id' => null,
                        'reason' => $reason,
                        'fallback' => true,
                        'mp_blocked' => $mpBlocked,
                        'next_rule_index' => count($actor->aiRules),
                    ];
                }
                if ($actor->skillReady($skillKey)
                    && $actor->mp < $this->effectiveCost($actor, (int) ($skill['mp_cost'] ?? 0))) {
                    $mpBlocked = true;
                }
            }
        }

        return [
            'type' => 'normal_attack',
            'key' => null,
            'target_id' => null,
            'reason' => $reason,
            'fallback' => true,
            'mp_blocked' => $mpBlocked,
            'next_rule_index' => count($actor->aiRules),
        ];
    }
}
