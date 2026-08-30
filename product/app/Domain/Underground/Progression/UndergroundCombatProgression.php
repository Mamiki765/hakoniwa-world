<?php

namespace App\Domain\Underground\Progression;

use InvalidArgumentException;

final class UndergroundCombatProgression
{
    public function levelAfterXp(
        int $currentLevel,
        int $totalXp,
        int $firstLevelCost,
        int $costIncrementPerLevel,
    ): int {
        if ($currentLevel < 1 || $totalXp < 0 || $firstLevelCost < 1 || $costIncrementPerLevel < 0) {
            throw new InvalidArgumentException('Underground combat progression values are invalid.');
        }

        $level = $currentLevel;
        while ($totalXp >= $this->totalXpRequiredForLevel(
            $level + 1,
            $firstLevelCost,
            $costIncrementPerLevel,
        )) {
            $level++;
        }

        return $level;
    }

    public function totalXpRequiredForLevel(
        int $level,
        int $firstLevelCost,
        int $costIncrementPerLevel,
    ): int {
        if ($level < 1 || $firstLevelCost < 1 || $costIncrementPerLevel < 0) {
            throw new InvalidArgumentException('Underground combat progression values are invalid.');
        }

        $completedLevels = $level - 1;

        return ($completedLevels * $firstLevelCost)
            + intdiv($completedLevels * ($completedLevels - 1) * $costIncrementPerLevel, 2);
    }

    public function xpRequiredForNextLevel(
        int $currentLevel,
        int $firstLevelCost,
        int $costIncrementPerLevel,
    ): int {
        if ($currentLevel < 1 || $firstLevelCost < 1 || $costIncrementPerLevel < 0) {
            throw new InvalidArgumentException('Underground combat progression values are invalid.');
        }

        return $firstLevelCost + (($currentLevel - 1) * $costIncrementPerLevel);
    }
}
