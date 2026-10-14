<?php

namespace App\Domain\Command;

final class CapitalDestructionPolicy
{
    public static function protects(string $commandKey, ?string $facilityKey): bool
    {
        return $facilityKey === 'capital'
            && in_array($commandKey, ['land_clear', 'land_level', 'excavate', 'territory_abandon'], true);
    }
}
