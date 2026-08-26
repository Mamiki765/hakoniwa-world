<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryDemographicPolicy
{
    /** @param array<string, mixed> $settings */
    public function enabled(array $settings): bool
    {
        return in_array($settings['key'] ?? null, [
            'hakoniwa-2s-plus-v17',
            'hakoniwa-2s-plus-v18',
        ], true);
    }

    /** @param array<string, mixed> $settings */
    public function naturalMaximum(array $settings, int $base, int $level): int
    {
        return $this->addPerLevel($settings, $base, $level, 'natural_maximum_per_level');
    }

    /** @param array<string, mixed> $settings */
    public function attractionMaximum(array $settings, int $base, int $level): int
    {
        return $this->addPerLevel($settings, $base, $level, 'attraction_maximum_per_level');
    }

    /** @param array<string, mixed> $settings */
    public function indomitableBonus(array $settings, int $population, int $level): int
    {
        if ($population < 0 || $level < 0) {
            throw new DomainException('Demographic population and skill level must be non-negative.');
        }
        $effect = $this->effect($settings, SecretarySkillCatalog::INDOMITABLE);
        $basisPoints = $effect['basis_points_per_level'] ?? null;
        if (! is_int($basisPoints) || $basisPoints !== 25
            || ($effect['rounding'] ?? null) !== 'floor'
            || ($effect['maximum'] ?? null) !== 'effective_natural_maximum'
            || ($effect['extra_random_draw'] ?? null) !== false) {
            throw new DomainException('The v17 Indomitable population effect contract is invalid.');
        }
        if ($level > 0 && $population > intdiv(PHP_INT_MAX, $level * $basisPoints)) {
            throw new DomainException('Indomitable population bonus exceeds the supported integer range.');
        }

        return intdiv($population * $level * $basisPoints, 10_000);
    }

    /** @param array<string, mixed> $settings */
    private function addPerLevel(array $settings, int $base, int $level, string $field): int
    {
        if ($base < 0 || $level < 0) {
            throw new DomainException('Demographic maximum inputs must be non-negative.');
        }
        $effect = $this->effect($settings, SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY);
        $perLevel = $effect[$field] ?? null;
        $expectedPerLevel = $field === 'natural_maximum_per_level' ? 50 : 100;
        if ($perLevel !== $expectedPerLevel
            || ($effect['capital_maximum_modifier'] ?? null) !== 0) {
            throw new DomainException('The v17 demographic maximum contract is invalid.');
        }
        if ($level > 0 && $perLevel > intdiv(PHP_INT_MAX - $base, $level)) {
            throw new DomainException('Demographic maximum exceeds the supported integer range.');
        }

        return $base + ($perLevel * $level);
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function effect(array $settings, string $skillKey): array
    {
        $effect = $settings['secretary']['skills'][$skillKey]['effect'] ?? null;
        if (! is_array($effect)) {
            throw new DomainException("The v17 demographic skill {$skillKey} effect is missing.");
        }

        return $effect;
    }
}
