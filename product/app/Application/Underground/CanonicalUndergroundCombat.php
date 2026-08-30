<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatEngine;

final readonly class CanonicalUndergroundCombat implements AtomicUndergroundCombat
{
    public function __construct(private UndergroundCombatEngine $engine) {}

    public function fight(
        string $actorKey,
        array $skillKeys,
        string $enemyKey,
        string $aiPreset,
        int $seed,
        int $maxRounds,
    ): CombatResult {
        return $this->engine->fight($actorKey, $skillKeys, $enemyKey, $aiPreset, $seed, $maxRounds);
    }
}
