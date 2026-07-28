<?php

namespace App\Domain\Turn;

use App\Models\Nation;

interface EconomyHandler
{
    public function apply(TurnContext $context, Nation $nation): TurnPhaseResult;
}
