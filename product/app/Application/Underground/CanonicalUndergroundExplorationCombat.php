<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\BuildCombatResult;

final readonly class CanonicalUndergroundExplorationCombat implements AtomicUndergroundExplorationCombat
{
    public function __construct(private AlphaV1CombatModel $model) {}

    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshot,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): BuildCombatResult {
        return $this->model->fightPlayerSnapshot(
            $catalog,
            $playerSnapshot,
            $enemyKey,
            $seed,
            $maxRounds,
            $naturalRecovery,
        );
    }
}
