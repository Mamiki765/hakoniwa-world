<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundBattleStatisticsProjector;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\PartyCombatResult;
use App\Domain\Underground\Combat\UndergroundAwakening;
use PHPUnit\Framework\TestCase;

final class UndergroundBattleStatisticsProjectorTest extends TestCase
{
    public function test_party_totals_and_leader_statistics_use_settled_actor_and_target_values(): void
    {
        $result = new PartyCombatResult(
            winner: 'player',
            rounds: 3,
            actionLog: [
                ['kind' => 'decision', 'actor_id' => 'leader', 'action_key' => 'normal_attack'],
                ['kind' => 'decision', 'actor_id' => 'leader', 'action_key' => 'leader_skill'],
                ['effect_type' => 'damage', 'actor_id' => 'leader', 'target_id' => 'enemy:1',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 7,
                    'hp_damage' => 7, 'prevented_damage' => 0, 'target_hp_after' => 0,
                    'damage_source' => 'direct', 'critical' => true, 'defeated' => true,
                    'action' => 'normal_attack', 'action_id' => 'leader:1'],
                ['effect_type' => 'damage', 'actor_id' => 'borrowed:2', 'target_id' => 'enemy:2',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 13,
                    'hp_damage' => 13, 'prevented_damage' => 0, 'target_hp_after' => 7,
                    'damage_source' => 'direct', 'defeated' => false],
                ['effect_type' => 'damage', 'actor_id' => 'leader', 'target_id' => 'enemy:2',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 0,
                    'hp_damage' => 0, 'prevented_damage' => 0, 'target_hp_after' => 7,
                    'damage_source' => 'direct', 'defeated' => false, 'complete_guarded' => true,
                    'complete_guard_prevented_damage' => 5, 'action' => 'leader_skill',
                    'action_id' => 'leader:2'],
                ['effect_type' => 'damage', 'actor_id' => 'enemy:2', 'team' => 'enemy',
                    'source_combatant_id' => 'leader', 'source_side' => 'player',
                    'periodic_target_combatant_id' => 'enemy:2', 'periodic_target_side' => 'enemy',
                    'effective_damage' => 2, 'hp_damage' => 2, 'prevented_damage' => 0,
                    'target_hp_after' => 5, 'damage_source' => 'periodic',
                    'periodic_status_key' => 'bleed', 'defeated' => false],
                ['effect_type' => 'damage', 'actor_id' => 'enemy:2', 'target_id' => 'leader',
                    'team' => 'enemy', 'target_side' => 'player', 'effective_damage' => 4,
                    'hp_damage' => 4, 'prevented_damage' => 3, 'target_hp_after' => 6,
                    'damage_source' => 'direct', 'defeated' => false],
                ['effect_type' => 'damage', 'actor_id' => 'enemy:2', 'target_id' => 'leader',
                    'team' => 'enemy', 'target_side' => 'player', 'effective_damage' => 6,
                    'hp_damage' => 6, 'prevented_damage' => 2, 'target_hp_after' => 0,
                    'damage_source' => 'direct', 'defeated' => true],
                ['effect_type' => 'damage', 'actor_id' => 'enemy:1', 'target_id' => 'borrowed:2',
                    'team' => 'enemy', 'target_side' => 'player', 'effective_damage' => 8,
                    'hp_damage' => 8, 'prevented_damage' => 1, 'target_hp_after' => 0,
                    'damage_source' => 'direct', 'defeated' => true],
                ['effect_type' => 'recovery', 'team' => 'player', 'actor_id' => 'leader',
                    'target_id' => 'borrowed:2', 'target_side' => 'player', 'amount' => -5,
                    'effect_source' => 'direct'],
                ['effect_type' => 'recovery', 'team' => 'player', 'actor_id' => 'leader',
                    'source_combatant_id' => 'leader', 'source_side' => 'player',
                    'periodic_target_combatant_id' => 'leader', 'periodic_target_side' => 'player',
                    'amount' => -3, 'effect_source' => 'periodic',
                    'periodic_status_key' => 'regeneration'],
                ['kind' => 'revival', 'effect_type' => 'revival', 'actor_id' => 'borrowed:2',
                    'team' => 'player', 'target_id' => 'leader', 'target_side' => 'player',
                    'amount' => -8, 'effect_source' => 'revival', 'revived' => true],
                ['kind' => 'revival', 'effect_type' => 'revival', 'actor_id' => 'leader',
                    'team' => 'player', 'target_id' => 'borrowed:2', 'target_side' => 'player',
                    'amount' => -4, 'effect_source' => 'revival', 'revived' => true],
                ['effect_type' => 'mp_cost', 'actor_id' => 'leader', 'amount' => 9],
                ['effect_type' => 'mp_recovery', 'actor_id' => 'leader', 'amount' => 3],
                ['kind' => 'awakening', 'actor_id' => 'leader', 'team' => 'player',
                    'round' => 2, 'effective_healing' => 0],
                ['kind' => 'awakening_technique', 'actor_id' => 'leader', 'round' => 3],
            ],
            initialStates: [
                'leader' => ['team' => 'player', 'hp' => 10, 'awakened' => false],
                'borrowed:2' => ['team' => 'player', 'hp' => 8, 'awakened' => true],
                'enemy:1' => ['team' => 'enemy', 'hp' => 7],
                'enemy:2' => ['team' => 'enemy', 'hp' => 20],
            ],
            finalStates: [
                'leader' => ['team' => 'player', 'hp' => 8],
                'borrowed:2' => ['team' => 'player', 'hp' => 4],
                'enemy:1' => ['team' => 'enemy', 'hp' => 0],
                'enemy:2' => ['team' => 'enemy', 'hp' => 7],
            ],
            metrics: [
                'damage_dealt' => 22,
                'damage_received' => 18,
                'effective_healing' => 20,
                'damage_prevented' => 6,
            ],
            awakening: [
                'leader' => ['triggered' => true],
                'borrowed:2' => ['triggered' => true],
            ],
        );

        $statistics = (new UndergroundBattleStatisticsProjector)->fromParty($result, 'leader');

        self::assertSame(2, $statistics['party_size']);
        self::assertSame(['complete' => true, 'issue_count' => 0, 'reasons' => []], $statistics['completeness']);
        self::assertSame([
            'damage_dealt' => 22,
            'damage_by_source' => ['direct' => 20, 'periodic' => 2, 'counter' => 0],
            'damage_received' => 18,
            'effective_healing' => 20,
            'healing_by_source' => [
                'direct' => 5, 'periodic' => 3, 'lifesteal' => 0,
                'regeneration' => 0, 'revival' => 12, 'awakening' => 0,
            ],
            'damage_prevented' => 6,
            'complete_guard_count' => 0,
            'complete_guard_prevented_damage' => 0,
            'knockouts' => 2,
            'revivals' => 2,
            'awakened_combatants' => 2,
        ], $statistics['party']);
        self::assertSame([
            'damage_dealt' => 9,
            'damage_by_source' => ['direct' => 7, 'periodic' => 2, 'counter' => 0],
            'maximum_hit' => 7,
            'maximum_hit_action_key' => 'normal_attack',
            'maximum_hit_damage_source' => 'direct',
            'damage_received' => 10,
            'effective_healing' => 12,
            'effective_healing_received' => 11,
            'healing_by_source' => [
                'direct' => 5, 'periodic' => 3, 'lifesteal' => 0,
                'regeneration' => 0, 'revival' => 4, 'awakening' => 0,
            ],
            'damage_prevented' => 5,
            'complete_guard_count' => 0,
            'complete_guard_prevented_damage' => 0,
            'enemy_complete_guard_count' => 1,
            'enemy_defeats' => 1,
            'normal_attacks' => 1,
            'skill_uses' => 1,
            'action_usage' => ['leader_skill' => 1, 'normal_attack' => 1],
            'critical_hits' => 1,
            'mp_spent' => 9,
            'mp_recovered' => 3,
            'minimum_hp' => 0,
            'ending_hp' => 8,
            'knockouts' => 1,
            'revivals_received' => 1,
            'revivals_performed' => 1,
            'awakened_at_start' => false,
            'awakened_in_battle' => true,
            'awakening_round' => 2,
            'awakening_technique_uses' => 1,
            'awakening_triggered' => true,
        ], $statistics['self']);
    }

    public function test_solo_player_side_uses_the_runtime_combatant_id_for_periodic_and_self_statistics(): void
    {
        $result = new BuildCombatResult(
            rulesIdentity: AlphaV1CombatRules::IDENTITY,
            generatorIdentity: AlphaV1CombatRules::GENERATOR_IDENTITY,
            seed: 1,
            buildKey: 'secretary_runtime',
            enemyKey: 'subterranean_rat',
            tierKey: 'runtime',
            winner: 'stalemate',
            rounds: 2,
            playerRemainingHp: 1000,
            enemyRemainingHp: 88,
            damageDealt: 12,
            damageReceived: 7,
            effectiveHealing: 707,
            damagePrevented: 0,
            mpSpent: 0,
            mpNaturalRecovery: 0,
            mpSkillRecovery: 0,
            mpOverflow: 0,
            mpExhaustionRound: null,
            skillUnavailableDueToMp: 0,
            emergencyHealOpportunities: 0,
            emergencyHealAvailable: 0,
            emergencyHealBlockedByMp: 0,
            crystalCycleRecovery: 0,
            finalMp: AlphaV1CombatRules::MAX_MP,
            actionUsage: ['normal_attack' => 1],
            statusUptime: [],
            finalRoleStacks: ['fighting_spirit' => 0, 'grace' => 0],
            mpHistory: [],
            abnormalState: [],
            actionLog: [
                ['kind' => 'decision', 'team' => 'player', 'actor_id' => 'secretary_runtime',
                    'action_key' => 'normal_attack'],
                ['effect_type' => 'damage', 'team' => 'player', 'actor_id' => 'secretary_runtime',
                    'target_side' => 'enemy', 'target_id' => 'enemy_runtime', 'effective_damage' => 10,
                    'hp_damage' => 10, 'prevented_damage' => 0, 'target_hp_after' => 90,
                    'damage_source' => 'direct', 'defeated' => false, 'action' => 'normal_attack'],
                ['effect_type' => 'damage', 'team' => 'enemy', 'actor_id' => 'enemy_runtime',
                    'source_combatant_id' => 'secretary_runtime', 'source_side' => 'player',
                    'periodic_target_combatant_id' => 'enemy_runtime', 'periodic_target_side' => 'enemy',
                    'effective_damage' => 2, 'hp_damage' => 2, 'prevented_damage' => 0,
                    'target_hp_after' => 88, 'damage_source' => 'periodic',
                    'periodic_status_key' => 'bleed', 'defeated' => false],
                ['effect_type' => 'damage', 'team' => 'enemy', 'actor_id' => 'enemy_runtime',
                    'target_side' => 'player', 'target_id' => 'secretary_runtime', 'effective_damage' => 4,
                    'hp_damage' => 4, 'prevented_damage' => 0, 'target_hp_after' => 296,
                    'damage_source' => 'direct', 'defeated' => false],
                ['effect_type' => 'damage', 'team' => 'player', 'actor_id' => 'secretary_runtime',
                    'source_combatant_id' => 'enemy_runtime', 'source_side' => 'enemy',
                    'periodic_target_combatant_id' => 'secretary_runtime', 'periodic_target_side' => 'player',
                    'effective_damage' => 3, 'hp_damage' => 3, 'prevented_damage' => 0,
                    'target_hp_after' => 293, 'damage_source' => 'periodic',
                    'periodic_status_key' => 'poison', 'defeated' => false],
                ['effect_type' => 'recovery', 'team' => 'player', 'actor_id' => 'secretary_runtime',
                    'source_combatant_id' => 'secretary_runtime', 'source_side' => 'player',
                    'periodic_target_combatant_id' => 'secretary_runtime', 'periodic_target_side' => 'player',
                    'amount' => -2, 'effect_source' => 'periodic',
                    'periodic_status_key' => 'regeneration'],
                ['effect_type' => 'recovery', 'team' => 'player', 'actor_id' => 'secretary_runtime',
                    'target_id' => 'secretary_runtime', 'target_side' => 'player',
                    'amount' => -5, 'effect_source' => 'regeneration'],
                ['kind' => 'awakening', 'effect_type' => 'awakening', 'team' => 'player',
                    'side' => 'player', 'actor_id' => 'secretary_runtime', 'target_id' => 'secretary_runtime',
                    'target_side' => 'player', 'round' => 2, 'effective_healing' => 700],
            ],
            generatedEquipment: [],
            awakening: [
                'identity' => UndergroundAwakening::IDENTITY,
                'unlocked' => true,
                'gauge_before' => UndergroundAwakening::GAUGE_MAX,
                'gauge_after' => 0,
                'gauge_gained' => 0,
                'triggered' => true,
                'normal_max_hp' => 1000,
                'final_max_hp' => 1000,
                'normal_stats' => [],
                'final_stats' => [],
                'technique' => null,
            ],
            initialState: [
                'player' => ['team' => 'player', 'combatant_id' => 'secretary_runtime',
                    'hp' => 300, 'awakened' => false],
                'enemy' => ['team' => 'enemy', 'combatant_id' => 'enemy_runtime', 'hp' => 100],
            ],
        );

        $statistics = (new UndergroundBattleStatisticsProjector)->fromSolo($result);

        self::assertSame(['complete' => true, 'issue_count' => 0, 'reasons' => []], $statistics['completeness']);
        self::assertSame(12, $statistics['self']['damage_dealt']);
        self::assertSame(['direct' => 10, 'periodic' => 2, 'counter' => 0], $statistics['self']['damage_by_source']);
        self::assertSame(7, $statistics['self']['damage_received']);
        self::assertSame(707, $statistics['self']['effective_healing']);
        self::assertSame(707, $statistics['self']['effective_healing_received']);
        self::assertSame([
            'direct' => 0, 'periodic' => 2, 'lifesteal' => 0,
            'regeneration' => 5, 'revival' => 0, 'awakening' => 700,
        ], $statistics['self']['healing_by_source']);
        self::assertSame(10, $statistics['self']['maximum_hit']);
        self::assertSame('normal_attack', $statistics['self']['maximum_hit_action_key']);
        self::assertSame(['normal_attack' => 1], $statistics['self']['action_usage']);
    }

    public function test_maximum_hit_is_the_total_for_one_action_sequence_with_a_deterministic_action_key(): void
    {
        $result = new PartyCombatResult(
            winner: 'player',
            rounds: 1,
            actionLog: [
                ['kind' => 'decision', 'actor_id' => 'leader', 'team' => 'player',
                    'action_key' => 'dagger_flurry'],
                ['effect_type' => 'damage', 'actor_id' => 'leader', 'target_id' => 'enemy:1',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 4,
                    'hp_damage' => 4, 'prevented_damage' => 0, 'target_hp_after' => 6,
                    'damage_source' => 'direct', 'defeated' => false,
                    'action' => 'dagger_flurry', 'action_id' => 'leader:1'],
                ['effect_type' => 'damage', 'actor_id' => 'leader', 'target_id' => 'enemy:1',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 6,
                    'hp_damage' => 6, 'prevented_damage' => 0, 'target_hp_after' => 0,
                    'damage_source' => 'direct', 'defeated' => true,
                    'action' => 'dagger_flurry', 'action_id' => 'leader:1'],
            ],
            initialStates: [
                'leader' => ['team' => 'player', 'hp' => 10, 'awakened' => false],
                'enemy:1' => ['team' => 'enemy', 'hp' => 10],
            ],
            finalStates: [
                'leader' => ['team' => 'player', 'hp' => 10],
                'enemy:1' => ['team' => 'enemy', 'hp' => 0],
            ],
            metrics: [
                'damage_dealt' => 10,
                'damage_received' => 0,
                'effective_healing' => 0,
                'damage_prevented' => 0,
            ],
            awakening: ['leader' => ['triggered' => false]],
        );

        $statistics = (new UndergroundBattleStatisticsProjector)->fromParty($result, 'leader');

        self::assertSame(10, $statistics['self']['maximum_hit']);
        self::assertSame('dagger_flurry', $statistics['self']['maximum_hit_action_key']);
        self::assertSame('direct', $statistics['self']['maximum_hit_damage_source']);
        self::assertSame(['dagger_flurry' => 1], $statistics['self']['action_usage']);
    }

    public function test_unclassified_current_event_marks_statistics_incomplete_without_throwing(): void
    {
        $result = new PartyCombatResult(
            winner: 'player',
            rounds: 1,
            actionLog: [[
                'effect_type' => 'damage',
                'actor_id' => 'leader',
                'target_id' => 'enemy:1',
                'team' => 'player',
                'target_side' => 'enemy',
                'amount' => 1,
            ]],
            initialStates: [
                'leader' => ['team' => 'player', 'hp' => 10, 'awakened' => false],
                'enemy:1' => ['team' => 'enemy', 'hp' => 1],
            ],
            finalStates: [
                'leader' => ['team' => 'player', 'hp' => 10],
                'enemy:1' => ['team' => 'enemy', 'hp' => 0],
            ],
            metrics: [
                'damage_dealt' => 1,
                'damage_received' => 0,
                'effective_healing' => 0,
                'damage_prevented' => 0,
            ],
            awakening: ['leader' => ['triggered' => false]],
        );

        $statistics = (new UndergroundBattleStatisticsProjector)->fromParty($result, 'leader');

        self::assertFalse($statistics['completeness']['complete']);
        self::assertSame(1, $statistics['completeness']['issue_count']);
        self::assertSame(['unclassified_damage_source' => 1], $statistics['completeness']['reasons']);
        self::assertSame(1, $statistics['party']['damage_dealt']);
        self::assertNull($statistics['party']['damage_by_source']);
        self::assertNull($statistics['self']['damage_dealt']);
    }
}
