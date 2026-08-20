<?php

namespace App\Domain\Monster;

final readonly class MonsterBehavior
{
    /** @param array<string, mixed>|null $worldSpawn */
    public function __construct(
        public string $movement,
        public bool $dispatchable,
        public bool $canActOnSpawnTurn,
        public string $specialAction,
        public bool $islandCreationDisplaceable,
        public ?array $worldSpawn,
        public bool $explicitlyAuthored,
    ) {}
}
