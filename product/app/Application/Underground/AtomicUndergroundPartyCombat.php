<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\PartyCombatResult;

interface AtomicUndergroundPartyCombat
{
    /**
     * @param  list<array<string, mixed>>  $playerSnapshots
     * @param  list<string>  $enemyKeys
     */
    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshots,
        array $enemyKeys,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): PartyCombatResult;
}
