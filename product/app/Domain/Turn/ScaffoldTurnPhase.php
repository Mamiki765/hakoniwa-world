<?php

namespace App\Domain\Turn;

use LogicException;

final readonly class ScaffoldTurnPhase implements TurnPhase
{
    public function __construct(
        private string $key,
        private bool $implemented,
        private bool $required = true,
        private string $legacyReference = '',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function implemented(): bool
    {
        return $this->implemented;
    }

    public function legacyReference(): string
    {
        return $this->legacyReference;
    }

    public function execute(TurnContext $context): TurnPhaseResult
    {
        if (! $this->implemented) {
            throw new LogicException("Turn phase {$this->key} is a required scaffold.");
        }

        return new TurnPhaseResult($this->key, ['scaffold_boundary' => true]);
    }
}
