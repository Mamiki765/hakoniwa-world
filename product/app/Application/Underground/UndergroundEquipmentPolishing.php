<?php

namespace App\Application\Underground;

use RuntimeException;

/** Projects upgrades over the saved roll; never regenerates or rewrites its payload. */
final class UndergroundEquipmentPolishing
{
    public function maximumLevel(): int
    {
        return (int) config('underground-equipment.polishing.maximum_level');
    }

    public function nextPrice(int $itemLevel, int $level): ?int
    {
        if ($level >= $this->maximumLevel()) {
            return null;
        }
        $bands = config('underground-equipment.polishing.costs_by_item_level');
        if (! is_array($bands) || $bands === []) {
            throw new RuntimeException('Underground polishing prices are missing.');
        }
        ksort($bands, SORT_NUMERIC);
        $prices = reset($bands);
        foreach ($bands as $threshold => $candidate) {
            if ($itemLevel >= $threshold) {
                $prices = $candidate;
            }
        }
        $price = is_array($prices) ? ($prices[$level] ?? null) : null;

        return is_int($price) && $price > 0 ? $price : throw new RuntimeException('Underground polishing price is invalid.');
    }

    /**
     * @param  array<string, mixed>  $definition  The immutable generated payload (or an IL-synced unpolished projection).
     * @return array<string, mixed>
     */
    public function apply(array $definition, int $level): array
    {
        if ($level === 0) {
            return $definition;
        }
        if ($level < 0 || $level > $this->maximumLevel() || ($definition['category'] ?? null) !== 'resonance'
            || ($definition['polish_level'] ?? 0) !== 0) {
            throw new RuntimeException('Underground polishing state is invalid.');
        }
        $statGain = (int) config('underground-equipment.polishing.fixed_stat_gain_per_level_bps') * $level;
        $percentageGain = (int) config('underground-equipment.polishing.percentage_gain_per_level_bps') * $level;
        foreach ($definition['base']['stats'] as $stat => $baseValue) {
            $definition['stats'][$stat] += intdiv($baseValue * $statGain + 5000, 10000);
        }
        foreach ($definition['affixes'] as &$affix) {
            $affix['value'] += $percentageGain;
            $definition['modifiers'][$affix['target']] += $percentageGain;
        }
        unset($affix);
        $definition['unique_effect']['value_bps'] += $percentageGain;
        $definition['modifiers'][$definition['unique_effect']['target']] += $percentageGain;
        $definition['polish_level'] = $level;

        return $definition;
    }
}
