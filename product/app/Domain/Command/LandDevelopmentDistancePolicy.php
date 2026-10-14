<?php

namespace App\Domain\Command;

use App\Models\CommandDefinition;

final class LandDevelopmentDistancePolicy
{
    public const RECLAMATION_RADIUS = 1;

    public const WATER_RADIUS = 3;

    public static function isUnderseaConstruction(CommandDefinition $command): bool
    {
        return ($command->metadata['construction_context'] ?? null) === 'undersea'
            && $command->target_type === 'cell' && $command->result_facility_key !== null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{int, bool}|null
     */
    public static function requirement(CommandDefinition $command, string $terrainKey, array $parameters = []): ?array
    {
        if ($command->key === 'reclaim') {
            return [self::RECLAMATION_RADIUS, false];
        }
        if ((self::isUnderseaConstruction($command) && ! isset($parameters['construction_ship_id']))
            || ($command->key === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true))) {
            return [self::WATER_RADIUS, true];
        }

        return null;
    }
}
