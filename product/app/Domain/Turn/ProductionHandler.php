<?php

namespace App\Domain\Turn;

use App\Models\Nation;

interface ProductionHandler
{
    public function produce(TurnContext $context, Nation $nation): TurnPhaseResult;
}
