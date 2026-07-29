<?php

namespace App\Domain\Turn;

use App\Models\RulesetVersion;
use App\Models\World;

final class RandomTurnSeedGenerator implements TurnSeedGenerator
{
    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return bin2hex(random_bytes(32));
    }
}
