<?php

namespace App\Domain\Turn;

use App\Models\Nation;

final readonly class CommandExecutionContext
{
    public function __construct(
        public TurnContext $turn,
        public Nation $nation,
    ) {}
}
