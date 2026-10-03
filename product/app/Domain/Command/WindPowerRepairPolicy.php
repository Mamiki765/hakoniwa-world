<?php

namespace App\Domain\Command;

use App\Models\CommandDefinition;

final class WindPowerRepairPolicy
{
    public static function matches(CommandDefinition $definition, ?string $facilityKey, ?string $operationalState): bool
    {
        return $definition->key === 'build_wind_power'
            && $facilityKey === 'wind_power'
            && $operationalState === 'damaged';
    }

    public static function costMoney(CommandDefinition $definition, ?string $facilityKey, ?string $operationalState): int
    {
        return self::matches($definition, $facilityKey, $operationalState)
            ? intdiv($definition->cost_money, 2)
            : $definition->cost_money;
    }
}
