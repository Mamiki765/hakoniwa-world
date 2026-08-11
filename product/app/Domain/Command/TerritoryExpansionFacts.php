<?php

namespace App\Domain\Command;

final readonly class TerritoryExpansionFacts
{
    /**
     * @param  list<string>  $definitionTargetTerrainKeys
     */
    public function __construct(
        public int $actorNationId,
        public string $actorNationState,
        public ?int $targetOwnerNationId,
        public ?string $targetOwnerNationState,
        public bool $targetOwnerInActorWorld,
        public string $terrainKey,
        public ?string $facilityKey,
        public bool $monsterOccupied,
        public bool $capitalCoreProtected,
        public bool $adjacentActorTerritory,
        public array $definitionTargetTerrainKeys,
        public bool $definitionRequiresEmptyFacility,
    ) {}
}
