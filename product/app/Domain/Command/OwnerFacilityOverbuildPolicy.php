<?php

namespace App\Domain\Command;

use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;

final class OwnerFacilityOverbuildPolicy
{
    public static function effect(CommandDefinition $definition, Nation $nation, MapCell $cell): ?string
    {
        return self::effectForState($definition, $nation, [
            'facility_key' => $cell->facility?->key,
            'owner_nation_id' => $cell->owner_nation_id,
        ]);
    }

    /**
     * @param  array{facility_key: string|null, owner_nation_id: int|null}  $state
     */
    public static function effectForState(CommandDefinition $definition, Nation $nation, array $state): ?string
    {
        $effect = $definition->metadata['owner_overbuild_effect'] ?? null;
        if (! is_string($effect) || $state['owner_nation_id'] !== $nation->id) {
            return null;
        }

        $expectedFacility = match ($effect) {
            'defense_self_destruct' => 'defense',
            'monument_flight' => 'monument',
            default => null,
        };

        return $expectedFacility !== null && $state['facility_key'] === $expectedFacility
            ? $effect
            : null;
    }
}
