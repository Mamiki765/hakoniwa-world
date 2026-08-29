<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;

interface AtomicUndergroundCombat
{
    /** @param list<string> $skillKeys */
    public function fight(
        string $actorKey,
        array $skillKeys,
        string $enemyKey,
        string $aiPreset,
        int $seed,
        int $maxRounds,
    ): CombatResult;
}
