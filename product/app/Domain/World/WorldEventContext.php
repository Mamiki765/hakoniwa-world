<?php

namespace App\Domain\World;

use App\Models\RulesetVersion;
use App\Models\World;

/** Event and economy inputs for an authorized mutation outside a TurnRun. */
final readonly class WorldEventContext
{
    public function __construct(
        public World $world,
        public RulesetVersion $ruleset,
        public int $targetTurn,
        public int $actorUserId,
    ) {}
}
