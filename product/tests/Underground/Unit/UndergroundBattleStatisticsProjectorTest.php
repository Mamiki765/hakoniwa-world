<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundBattleStatisticsProjector;
use App\Domain\Underground\Combat\PartyCombatResult;
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
                    'damage_source' => 'direct', 'critical' => true, 'defeated' => true],
                ['effect_type' => 'damage', 'actor_id' => 'borrowed:2', 'target_id' => 'enemy:2',
                    'team' => 'player', 'target_side' => 'enemy', 'effective_damage' => 13,
                    'hp_damage' => 13, 'prevented_damage' => 0, 'target_hp_after' => 7,
                    'damage_source' => 'direct', 'defeated' => false],
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
                ['kind' => 'awakening', 'actor_id' => 'leader', 'round' => 2],
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
            'enemy_defeats' => 1,
            'normal_attacks' => 1,
            'skill_uses' => 1,
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
