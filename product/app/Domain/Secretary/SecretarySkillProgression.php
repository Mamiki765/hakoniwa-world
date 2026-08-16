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
        while ($experience >= ($required = $this->requiredExperience($definition, $level))) {
            $experience -= $required;
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
