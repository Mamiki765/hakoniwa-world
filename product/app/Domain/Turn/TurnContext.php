<?php

namespace App\Domain\Turn;

use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;

final readonly class TurnContext
{
    public function __construct(
        public World $world,
        public TurnRun $run,
        public RulesetVersion $ruleset,
        public int $targetTurn,
        public string $randomSeed,
    ) {}
}
