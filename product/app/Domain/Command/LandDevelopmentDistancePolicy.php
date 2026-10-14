<?php

namespace App\Domain\Command;

final class LandDevelopmentDistancePolicy
{
    public const RECLAMATION_RADIUS = 1;

    public const WATER_RADIUS = 3;

    public static function isUnderseaConstruction(string $commandKey): bool
    {
        return in_array($commandKey, ['build_seabed_base', 'build_undersea_city', 'build_undersea_fire_station'], true);
    }

    /** @return array{int, bool}|null */
    public static function requirement(string $commandKey, string $terrainKey): ?array
    {
        if ($commandKey === 'reclaim') {
            return [self::RECLAMATION_RADIUS, false];
        }
        if (self::isUnderseaConstruction($commandKey)
            || ($commandKey === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true))) {
            return [self::WATER_RADIUS, true];
        }

        return null;
    }
}
