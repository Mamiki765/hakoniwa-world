<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\BuildCombatResult;

interface AtomicUndergroundExplorationCombat
{
    /** @param array<string, mixed> $playerSnapshot */
    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshot,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): BuildCombatResult;
}
