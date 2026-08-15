<?php

namespace App\Domain\Command;

use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;

final class OwnerFacilityOverbuildPolicy
{
    public static function effect(CommandDefinition $definition, Nation $nation, MapCell $cell): ?string
    {
        $effect = $definition->metadata['owner_overbuild_effect'] ?? null;
        if (! is_string($effect) || $cell->owner_nation_id !== $nation->id) {
            return null;
        }

        $expectedFacility = match ($effect) {
            'defense_self_destruct' => 'defense',
            'monument_flight' => 'monument',
            default => null,
        };

        return $expectedFacility !== null && $cell->facility?->key === $expectedFacility
            ? $effect
            : null;
    }
}
