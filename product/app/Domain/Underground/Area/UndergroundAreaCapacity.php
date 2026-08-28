<?php

namespace App\Domain\Underground\Area;

use InvalidArgumentException;

final class UndergroundAreaCapacity
{
    public const FACILITY_SLOTS_PER_LAYER = 4;

    public static function forUnlockedLayers(int $unlockedLayers): int
    {
        if ($unlockedLayers < 0) {
            throw new InvalidArgumentException('Unlocked Underground area layers cannot be negative.');
        }

        return $unlockedLayers * self::FACILITY_SLOTS_PER_LAYER;
    }
}
