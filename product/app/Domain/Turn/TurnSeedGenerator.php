<?php

namespace App\Domain\Turn;

use App\Models\RulesetVersion;
use App\Models\World;

interface TurnSeedGenerator
{
    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string;
}
