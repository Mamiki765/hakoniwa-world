<?php

namespace App\Domain\Command;

final class SettlementOverbuildPolicy
{
    /** @var list<string> */
    private const COMMAND_KEYS = [
        'plant_forest',
        'build_farm',
        'build_factory',
        'build_missile_base',
        'build_defense_facility',
        'build_monument',
        'build_decoy',
    ];

    /** @var list<string> */
    private const REPLACEABLE_FACILITY_KEYS = ['village', 'town', 'city'];

    public static function allows(string $commandKey, ?string $facilityKey): bool
    {
        return in_array($commandKey, self::COMMAND_KEYS, true)
            && in_array($facilityKey, self::REPLACEABLE_FACILITY_KEYS, true);
    }

    public static function protectsCapital(string $commandKey, ?string $facilityKey): bool
    {
        return $facilityKey === 'capital'
            && in_array($commandKey, self::COMMAND_KEYS, true);
    }
}
