<?php

namespace App\Application;

use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Domain\World\WorldMutationLock;
use App\Models\TurnRun;
use App\Models\World;

final readonly class NextProductionTurnRunGuard
{
    public function __construct(private WorldMutationLock $worldMutationLock) {}

    public function assertClear(World $world): void
    {
        $this->worldMutationLock->assertHeld($world);
        $run = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $world->current_turn + 1)
            ->unresolvedProduction()
            ->orderBy('id')
            ->first(['id', 'world_id', 'target_turn', 'status']);
        if ($run instanceof TurnRun) {
            throw new UnresolvedNextTurnRunException($run);
        }
    }
}
