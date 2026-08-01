<?php

namespace App\Domain\Turn;

use App\Application\CompleteTurnEngine;

final readonly class GameplayTurnPhase implements TurnPhase
{
    public function __construct(
        private string $phaseKey,
        private CompleteTurnEngine $engine,
    ) {}

    public function key(): string
    {
        return $this->phaseKey;
    }

    public function required(): bool
    {
        return true;
    }

    public function implemented(): bool
    {
        return true;
    }

    public function execute(TurnContext $context): TurnPhaseResult
    {
        return $this->engine->execute($this->phaseKey, $context);
    }
}
