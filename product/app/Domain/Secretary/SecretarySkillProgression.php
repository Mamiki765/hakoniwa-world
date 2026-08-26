<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretarySkillProgression
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function requiredExperience(array $definition, int $currentLevel): int
    {
        if ($currentLevel < 0) {
            throw new DomainException('Secretary skill level cannot be negative.');
        }
        $requirement = $definition['level_requirement'] ?? null;
        if (! is_array($requirement)) {
            throw new DomainException('Secretary skill level requirement is missing.');
        }
        $basis = $requirement['basis'] ?? null;
        $multiplier = $requirement['multiplier'] ?? null;
        if (! is_int($multiplier) || $multiplier < 1) {
            throw new DomainException('Secretary skill level requirement multiplier must be positive.');
        }
        if ($basis === 'triangular_growth') {
            if ($currentLevel > 3_000_000_000) {
                throw new DomainException('Secretary triangular skill level exceeds the supported integer range.');
            }
            $triangular = intdiv($currentLevel * ($currentLevel + 1), 2);
            if ($triangular > intdiv(PHP_INT_MAX, $multiplier) - 1) {
                throw new DomainException('Secretary skill level requirement exceeds the supported integer range.');
            }

            return (1 + $triangular) * $multiplier;
        }
        $levelBasis = match ($basis) {
            'next_level_squared' => $currentLevel + 1,
            'current_level_squared' => $currentLevel,
            default => throw new DomainException('Secretary skill level requirement basis is invalid.'),
        };
        if ($levelBasis < 1) {
            throw new DomainException('Secretary skill level requirement cannot resolve to zero.');
        }
        if ($levelBasis > intdiv(PHP_INT_MAX, $levelBasis)
            || $levelBasis * $levelBasis > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new DomainException('Secretary skill level requirement exceeds the supported integer range.');
        }

        return $levelBasis * $levelBasis * $multiplier;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{level: int, experience: int, levels_gained: int}
     */
    public function advance(
        array $definition,
        int $currentLevel,
        int $currentExperience,
        int $gainedExperience,
    ): array {
        if ($currentExperience < 0 || $gainedExperience < 0) {
            throw new DomainException('Secretary skill experience cannot be negative.');
        }
        if ($currentExperience > PHP_INT_MAX - $gainedExperience) {
            throw new DomainException('Secretary skill experience exceeds the supported integer range.');
        }

        $level = $currentLevel;
        $experience = $currentExperience + $gainedExperience;
        $levelsGained = 0;
        $accounting = $definition['level_requirement']['accounting'] ?? 'consume_required_carry_remainder';
        if (! in_array($accounting, ['consume_required_carry_remainder', 'cumulative_non_consuming'], true)) {
            throw new DomainException('Secretary skill experience accounting mode is invalid.');
        }
        while ($experience >= ($required = $this->requiredExperience($definition, $level))) {
            if ($accounting === 'consume_required_carry_remainder') {
                $experience -= $required;
            }
            $level++;
            $levelsGained++;
        }

        return [
            'level' => $level,
            'experience' => $experience,
            'levels_gained' => $levelsGained,
        ];
    }
}
