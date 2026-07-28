<?php

namespace App\Domain\Turn;

interface TurnPhase
{
    public function key(): string;

    public function required(): bool;

    public function implemented(): bool;

    public function execute(TurnContext $context): TurnPhaseResult;
}
