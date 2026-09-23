<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

/** Shared persisted equipment-effect contract for the catalog and combat snapshots. */
final class EquipmentCombatEffects
{
    public const RESONANCE_MODIFIERS = [
        'resonance_single_damage_bps',
        'resonance_area_damage_bps',
        'resonance_normal_damage_bps',
        'resonance_skill_damage_bps',
        'resonance_single_healing_bps',
        'resonance_area_healing_bps',
        'resonance_guard_reduction_bps',
    ];

    public static function assertUnique(mixed $effect): void
    {
        if ($effect === null) {
            return;
        }
        if (! is_array($effect)
            || ! is_string($effect['key'] ?? null) || $effect['key'] === ''
            || ! is_string($effect['label'] ?? null) || $effect['label'] === '') {
            throw new InvalidArgumentException('Underground equipment intrinsic effect is invalid.');
        }
        if (($effect['type'] ?? null) === 'resonance') {
            if (! in_array($effect['target'] ?? null, self::RESONANCE_MODIFIERS, true)
                || ! is_int($effect['value_bps'] ?? null)
                || $effect['value_bps'] < 1 || $effect['value_bps'] > 3_000) {
                throw new InvalidArgumentException('Underground resonance intrinsic effect is invalid.');
            }

            return;
        }
        if (($effect['type'] ?? null) !== 'shockwave'
            || ! in_array($effect['category'] ?? null, ['physical', 'miracle'], true)
            || ! is_int($effect['chance_bps'] ?? null) || $effect['chance_bps'] < 1 || $effect['chance_bps'] > 10_000
            || ! is_int($effect['potency_bps'] ?? null) || $effect['potency_bps'] < 1
            || ! is_array($effect['stat_coefficients'] ?? null)) {
            throw new InvalidArgumentException('Underground weapon intrinsic effect is invalid.');
        }
        foreach ($effect['stat_coefficients'] as $stat => $value) {
            if (! in_array($stat, AlphaV1CombatRules::STATS, true) || ! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Underground weapon effect coefficients are invalid.');
            }
        }
        if (array_sum($effect['stat_coefficients']) !== 10_000) {
            throw new InvalidArgumentException('Underground weapon effect coefficient sum is invalid.');
        }
    }

    /** @param array<string, int|bool|string> $modifiers */
    public static function damageBonus(array $modifiers, string $scope, string $source): int
    {
        $bonus = (int) ($modifiers[$scope === 'all_enemies'
            ? 'resonance_area_damage_bps' : 'resonance_single_damage_bps'] ?? 0);

        return $bonus + match ($source) {
            'normal' => (int) ($modifiers['resonance_normal_damage_bps'] ?? 0),
            'skill' => (int) ($modifiers['resonance_skill_damage_bps'] ?? 0),
            default => 0,
        };
    }

    /** @param array<string, int|bool|string> $modifiers */
    public static function healingBonus(array $modifiers, string $scope): int
    {
        return (int) ($modifiers[$scope === 'all_allies'
            ? 'resonance_area_healing_bps' : 'resonance_single_healing_bps'] ?? 0);
    }
}
