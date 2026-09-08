<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\PartyCombatResult;

final readonly class CanonicalUndergroundPartyCombat implements AtomicUndergroundPartyCombat
{
    public function __construct(private AlphaV1CombatModel $model) {}

    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshots,
        array $enemyKeys,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): PartyCombatResult {
        return $this->model->fightPartySnapshots(
            $catalog,
            $playerSnapshots,
            $enemyKeys,
            $seed,
            $maxRounds,
            $naturalRecovery,
        );
    }
}
