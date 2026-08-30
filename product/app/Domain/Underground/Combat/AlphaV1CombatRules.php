<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final class AlphaV1CombatRules
{
    public const IDENTITY = 'secretary-underground-alpha-v1';

    public const SIMULATOR_VERSION = 'underground-build-balance-alpha-v1';

    public const GENERATOR_IDENTITY = 'secretary-underground-equipment-alpha-v1';

    public const TARGETING_IDENTITY = 'secretary-underground-targeting-alpha-v1';

    public const TAUNT_KEY = 'taunt';

    public const TAUNT_TARGETING_SCOPE = 'default_hostile_single_target';

    public const MAX_MP = 10_000;

    public const ACTIVE_SKILL_LIMIT = 5;

    public const AI_RULE_LIMIT = 16;

    public const BUILD_POINT_BUDGET = 120;

    public const DAMAGE_REDUCTION_CAP_BPS = 7_500;

    public const EVASION_CAP_BPS = 3_500;

    public const ACTION_IMPAIRMENT_RESISTANCE_CAP_BPS = 5_000;

    public const CRITICAL_CHANCE_CAP_BPS = 6_000;

    public const MP_COST_REDUCTION_CAP_BPS = 4_000;

    /** @var list<string> */
    public const STATS = ['vitality', 'might', 'finesse', 'spirit', 'agility'];

    /** @var list<string> */
    public const TREES = ['martial', 'guardianship', 'miracle'];

    /** @var list<string> */
    public const WEAPON_STYLES = ['dagger', 'rapier', 'shield', 'crystal_staff'];

    /** @var list<string> */
    public const EQUIPMENT_SLOTS = ['weapon', 'armor', 'charm'];

    /** @var list<string> */
    public const STATUS_EFFECT_TYPES = [
        'periodic_damage',
        'periodic_heal',
        'stat_modifier',
        'damage_dealt_modifier',
        'damage_taken_modifier',
        'barrier',
        'action_impairment',
        'initiative_modifier',
        'cleanse',
        'dispel',
    ];

    public function progressionScaleBps(int $combatLevel, int $itemLevel): int
    {
        if ($combatLevel < 1 || $itemLevel < 1 || $combatLevel > 1_000 || $itemLevel > 1_000) {
            throw new InvalidArgumentException('Underground alpha-v1 level and item level must be between 1 and 1000.');
        }

        return 10_000 + ((max($combatLevel, $itemLevel) - 1) * 900);
    }

    public function storyBenchmarkScaleBps(int $equivalentCombatLevel): int
    {
        if ($equivalentCombatLevel < 1 || $equivalentCombatLevel > 10_000) {
            throw new InvalidArgumentException('Underground story benchmark level is invalid.');
        }

        return 10_000 + (($equivalentCombatLevel - 1) * 900);
    }

    /**
     * @param  array<string, int>  $baseStats
     * @param  array<string, int>  $equipmentStats
     * @return array<string, int>
     */
    public function scaledStats(array $baseStats, array $equipmentStats, int $scaleBps): array
    {
        $this->assertFiveStats($baseStats);
        $stats = [];
        foreach (self::STATS as $key) {
            $stats[$key] = max(1, intdiv($baseStats[$key] * $scaleBps, 10_000)
                + ($equipmentStats[$key] ?? 0));
        }

        return $stats;
    }

    /** @param array<string, int> $stats */
    public function maxHp(array $stats, int $scaleBps, int $equipmentHp = 0): int
    {
        $this->assertFiveStats($stats, false);
        $baselineVitality = max(1, intdiv(20 * $scaleBps, 10_000));
        $baselineHp = max(1, intdiv(500 * $scaleBps, 10_000));

        return max(1, $baselineHp + (($stats['vitality'] - $baselineVitality) * 8) + $equipmentHp);
    }

    public function defenseReference(int $scaleBps): int
    {
        return max(1, intdiv(100 * $scaleBps, 10_000));
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, mixed>  $coefficientsBps
     */
    public function weightedStats(array $stats, array $coefficientsBps): int
    {
        $scaledTotal = 0;
        foreach ($coefficientsBps as $key => $coefficient) {
            if (! in_array($key, self::STATS, true) || ! is_int($coefficient) || $coefficient < 0) {
                throw new InvalidArgumentException('Underground alpha-v1 stat coefficient is invalid.');
            }
            $scaledTotal += ($stats[$key] ?? 0) * $coefficient;
        }

        return max(0, intdiv($scaledTotal, 10_000));
    }

    /** @param array<string, mixed> $stats */
    public function assertFiveStats(array $stats, bool $requireBaseBudget = true): void
    {
        if (array_keys($stats) !== self::STATS) {
            throw new InvalidArgumentException('Underground alpha-v1 requires the five ordered base stats.');
        }
        foreach ($stats as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Underground alpha-v1 stats must be positive integers.');
            }
        }
        if ($requireBaseBudget && array_sum($stats) !== 100) {
            throw new InvalidArgumentException('Underground alpha-v1 representative base stats must total 100.');
        }
    }
}
