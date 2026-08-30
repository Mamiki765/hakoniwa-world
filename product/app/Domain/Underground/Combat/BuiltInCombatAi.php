<?php

namespace App\Domain\Underground\Combat;

final class BuiltInCombatAi
{
    /** @return array{type: 'normal_attack'|'defend'|'skill', key: string|null, reason: string, fallback: bool} */
    public function playerAction(
        CombatantState $actor,
        CombatantState $enemy,
    ): array {
        if ($enemy->telegraphing) {
            return $this->action('defend', null, 'enemy_telegraph', false);
        }

        if ($actor->hp * 100 <= $actor->maxHp * 40
            && $actor->skillReady('mending_light')) {
            return $this->action('skill', 'mending_light', 'low_hp_heal', false);
        }

        if ($actor->resource >= UndergroundCombatRules::RESOURCE_CAP
            && $actor->skillReady('crystal_burst')) {
            return $this->action('skill', 'crystal_burst', 'resource_finisher', false);
        }

        if ($enemy->defense >= 120 && $actor->skillReady('piercing_thrust')) {
            return $this->action('skill', 'piercing_thrust', 'armored_target', false);
        }

        if ($actor->skillReady('quick_slash')) {
            return $this->action('skill', 'quick_slash', 'available_damage_skill', false);
        }

        if ($actor->hp * 100 <= $actor->maxHp * 25 && ! $actor->guarding) {
            return $this->action('defend', null, 'low_hp_guard', false);
        }

        return $this->action('normal_attack', null, 'built_in_fallback', true);
    }

    /** @return array{type: 'normal_attack'|'defend'|'enemy_fast_strike'|'enemy_telegraph'|'enemy_heavy_strike', key: null, reason: string, fallback: bool} */
    public function enemyAction(CombatantState $enemy, int $round): array
    {
        if ($enemy->behavior === 'telegraph') {
            if ($enemy->telegraphing) {
                return $this->enemyActionRow('enemy_heavy_strike', 'prepared_heavy_strike');
            }
            if ($round % 3 === 2) {
                return $this->enemyActionRow('enemy_telegraph', 'telegraph_cycle');
            }
        }

        if ($enemy->behavior === 'fast' && $round % 2 === 1) {
            return $this->enemyActionRow('enemy_fast_strike', 'fast_cycle');
        }

        if ($enemy->behavior === 'armored' && $round % 4 === 0) {
            return $this->enemyActionRow('defend', 'shell_guard_cycle');
        }

        return $this->enemyActionRow('normal_attack', 'standard_attack');
    }

    /** @return array{type: 'normal_attack'|'defend'|'skill', key: string|null, reason: string, fallback: bool} */
    private function action(string $type, ?string $key, string $reason, bool $fallback): array
    {
        return compact('type', 'key', 'reason', 'fallback');
    }

    /** @return array{type: 'normal_attack'|'defend'|'enemy_fast_strike'|'enemy_telegraph'|'enemy_heavy_strike', key: null, reason: string, fallback: bool} */
    private function enemyActionRow(string $type, string $reason): array
    {
        return ['type' => $type, 'key' => null, 'reason' => $reason, 'fallback' => false];
    }
}
