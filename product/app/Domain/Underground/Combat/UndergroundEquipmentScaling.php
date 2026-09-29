<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

/** The shared integer boundary for post-IL200 output and equipment ratings. */
final class UndergroundEquipmentScaling
{
    public const BASE_BPS = 10_000;

    /** @var list<string> */
    public const RATING_TARGETS = [
        'physical_damage_bps', 'miracle_damage_bps', 'healing_bps', 'barrier_bps',
        'critical_chance_bps', 'critical_damage_bps', 'mp_cost_reduction_bps',
        ...EquipmentCombatEffects::RESONANCE_MODIFIERS,
    ];

    public static function scaleBps(int $itemLevel): int
    {
        if ($itemLevel < 1 || $itemLevel > 220) {
            throw new InvalidArgumentException('Equipment item level is outside the supported generation range.');
        }

        return (int) round(self::BASE_BPS * (1.1 ** (max(0, $itemLevel - 200) / 10)));
    }

    public static function requirementBps(int $weaponLevel, ?int $armorLevel): int
    {
        return self::scaleBps(max(200, min($weaponLevel, $armorLevel ?? 200)));
    }

    public static function scaled(int $value, int $scaleBps): int
    {
        if ($value < 0 || $scaleBps < self::BASE_BPS) {
            throw new InvalidArgumentException('Equipment scaling input is invalid.');
        }

        return intdiv($value * $scaleBps + 5_000, self::BASE_BPS);
    }

    public static function effectiveBps(int $rating, int $requirementBps, string $target): int
    {
        if ($rating < 0 || $requirementBps < self::BASE_BPS) {
            throw new InvalidArgumentException('Equipment rating input is invalid.');
        }
        $effective = intdiv($rating * self::BASE_BPS + intdiv($requirementBps, 2), $requirementBps);

        return $target === 'resonance_guard_reduction_bps' ? min(9_000, $effective) : $effective;
    }

    public static function isRatingTarget(string $target): bool
    {
        return in_array($target, self::RATING_TARGETS, true);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, mixed>  $equipment
     */
    public static function maxHp(AlphaV1CombatRules $rules, array $stats, int $statScaleBps, array $equipment): int
    {
        $affixBase = (int) ($equipment['max_hp_affix_base'] ?? 0);
        $affixScaled = (int) ($equipment['max_hp_affix_scaled'] ?? 0);
        $equipmentHp = (int) $equipment['max_hp'];
        if ($affixBase < 0 || $affixScaled < 0 || $affixBase > $equipmentHp) {
            throw new InvalidArgumentException('Equipment HP affix projection is invalid.');
        }

        return self::scaled(
            $rules->maxHp($stats, $statScaleBps, $equipmentHp - $affixBase),
            (int) ($equipment['armor_scale_bps'] ?? self::BASE_BPS),
        ) + $affixScaled;
    }
}
