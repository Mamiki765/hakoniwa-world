<?php

namespace App\Domain\Command;

use App\Models\CommandDefinition;

final class WindPowerRepairPolicy
{
    // 故障して修理を待っている施設か。地図の「！」と、修理コマンドを先頭に出す判定もここに寄せる。
    public static function needsRepair(?string $facilityKey, ?string $operationalState): bool
    {
        return $facilityKey === 'wind_power' && $operationalState === 'damaged';
    }

    public static function matches(CommandDefinition $definition, ?string $facilityKey, ?string $operationalState): bool
    {
        return $definition->key === 'build_wind_power'
            && self::needsRepair($facilityKey, $operationalState);
    }

    public static function costMoney(CommandDefinition $definition, ?string $facilityKey, ?string $operationalState): int
    {
        return self::matches($definition, $facilityKey, $operationalState)
            ? intdiv($definition->cost_money, 2)
            : $definition->cost_money;
    }
}
