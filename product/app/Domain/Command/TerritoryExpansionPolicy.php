<?php

namespace App\Domain\Command;

final class TerritoryExpansionPolicy
{
    /** @param array<string, mixed> $metadata */
    public function failureReason(array $metadata, TerritoryExpansionFacts $facts): ?CommandFailureReason
    {
        if (isset($metadata['actor_states'])
            && ! in_array($facts->actorNationState, $metadata['actor_states'], true)) {
            return CommandFailureReason::InvalidTargetNation;
        }
        if (! in_array($facts->terrainKey, $facts->definitionTargetTerrainKeys, true)) {
            return CommandFailureReason::InvalidTerrain;
        }
        if (($metadata['monster_occupancy'] ?? 'reject') === 'reject' && $facts->monsterOccupied) {
            return CommandFailureReason::OccupiedByMonster;
        }
        if ($facts->targetOwnerNationId === $facts->actorNationId) {
            return CommandFailureReason::AlreadyOwned;
        }
        if ($facts->definitionRequiresEmptyFacility && $facts->facilityKey !== null) {
            return CommandFailureReason::FacilityExists;
        }
        if (! $facts->adjacentActorTerritory) {
            return CommandFailureReason::MissingAdjacentTerritory;
        }
        if ($facts->capitalCoreProtected) {
            return CommandFailureReason::CapitalProtected;
        }

        if ($facts->targetOwnerNationId === null) {
            if (isset($metadata['neutral_target']) && is_array($metadata['neutral_target'])) {
                $neutral = $metadata['neutral_target'];

                return ($neutral['allowed'] ?? false) === true
                    && in_array($facts->terrainKey, $neutral['terrain_keys'] ?? [], true)
                    ? null
                    : CommandFailureReason::InvalidTerrain;
            }

            return ($metadata['neutral_only'] ?? false) === true
                ? null
                : CommandFailureReason::InvalidTerrain;
        }

        if (! $facts->targetOwnerInActorWorld) {
            return CommandFailureReason::InvalidTargetNation;
        }

        $foreign = $metadata['foreign_target'] ?? null;
        if (! is_array($foreign)) {
            return CommandFailureReason::ForeignOwned;
        }
        if (! in_array($facts->actorNationState, $metadata['actor_states'] ?? [], true)
            || $facts->targetOwnerNationState === null
            || ! in_array($facts->targetOwnerNationState, $foreign['owner_states'] ?? [], true)) {
            return CommandFailureReason::InvalidTargetNation;
        }
        if (! in_array($facts->terrainKey, $foreign['terrain_keys'] ?? [], true)) {
            return CommandFailureReason::ForeignOwned;
        }

        return null;
    }

    public function message(CommandFailureReason $reason): string
    {
        return match ($reason) {
            CommandFailureReason::InvalidTerrain => '対象地形では領土拡張できません。',
            CommandFailureReason::OccupiedByMonster => '怪獣が存在するcellへ領土拡張できません。',
            CommandFailureReason::AlreadyOwned => 'すでに自国領のcellです。',
            CommandFailureReason::FacilityExists => '施設のあるcellへ領土拡張できません。',
            CommandFailureReason::MissingAdjacentTerritory => '対象cellの6方向隣接に自国領がありません。',
            CommandFailureReason::CapitalProtected => 'Capital core内の所有権を他Nationへ移せません。',
            CommandFailureReason::InvalidTargetNation => 'activeではないNationの領土は取得できません。',
            CommandFailureReason::ForeignOwned => '取得できない他国領です。',
            default => '領土拡張の対象条件を満たしていません。',
        };
    }
}
