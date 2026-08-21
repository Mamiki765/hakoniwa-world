<?php

namespace App\Domain\Turn;

use App\Models\TurnRun;
use DomainException;

final class UnresolvedNextTurnRunException extends DomainException
{
    public function __construct(public readonly TurnRun $turnRun)
    {
        parent::__construct('The next production TurnRun is unresolved.');
    }
}
