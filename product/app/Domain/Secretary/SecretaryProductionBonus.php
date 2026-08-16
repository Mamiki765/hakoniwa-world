<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryProductionBonus
{
    /** @param array<string, mixed> $ruleset */
    public function apply(array $ruleset, string $skillKey, int $level, int $baseProduction): int
    {
        if ($baseProduction < 0 || $level < 0) {
            throw new DomainException('Secretary production inputs must be non-negative integers.');
        }
        if (! isset($ruleset['secretary'])) {
            return $baseProduction;
        }
        $definition = $ruleset['secretary']['skills'][$skillKey] ?? null;
        $perMille = is_array($definition) ? ($definition['effect']['per_mille_per_level'] ?? null) : null;
        if (! is_int($perMille) || $perMille < 0) {
            throw new DomainException("Secretary production skill {$skillKey} has an invalid multiplier.");
        }
        if ($level !== 0 && $perMille > intdiv(PHP_INT_MAX - 1000, $level)) {
            throw new DomainException('Secretary production multiplier exceeds the supported integer range.');
        }
        $multiplier = 1000 + ($level * $perMille);
        $whole = intdiv($baseProduction, 1000);
        $remainder = $baseProduction % 1000;
        if ($whole !== 0 && $multiplier > intdiv(PHP_INT_MAX, $whole)) {
            throw new DomainException('Secretary production result exceeds the supported integer range.');
        }
        if ($remainder !== 0 && $multiplier > intdiv(PHP_INT_MAX, $remainder)) {
            throw new DomainException('Secretary production result exceeds the supported integer range.');
        }

        return ($whole * $multiplier) + intdiv($remainder * $multiplier, 1000);
    }
}
