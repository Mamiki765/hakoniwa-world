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
    private const VERSIONED_COMMAND_KEYS = [
        'build_central_bank',
        'build_central_granary',
    ];

    /** @var list<string> */
    private const REPLACEABLE_FACILITY_KEYS = ['village', 'town', 'city'];

    /** @param array<string, mixed> $metadata */
    public static function allows(string $commandKey, ?string $facilityKey, array $metadata = []): bool
    {
        return self::isEnabled($commandKey, $metadata)
            && in_array($facilityKey, self::REPLACEABLE_FACILITY_KEYS, true);
    }

    /** @param array<string, mixed> $metadata */
    public static function protectsCapital(string $commandKey, ?string $facilityKey, array $metadata = []): bool
    {
        return $facilityKey === 'capital'
            && self::isEnabled($commandKey, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private static function isEnabled(string $commandKey, array $metadata): bool
    {
        return in_array($commandKey, self::COMMAND_KEYS, true)
            || (in_array($commandKey, self::VERSIONED_COMMAND_KEYS, true)
                && ($metadata['settlement_overbuild'] ?? false) === true);
    }
}
