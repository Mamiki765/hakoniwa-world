<?php

namespace App\Domain\Turn;

final readonly class TurnPhaseResult
{
    /** @param array<string, int|string|bool|null> $metrics */
    public function __construct(
        public string $phase,
        public array $metrics = [],
    ) {}

    /** @return array{phase: string, metrics: array<string, int|string|bool|null>} */
    public function toArray(): array
    {
        return ['phase' => $this->phase, 'metrics' => $this->metrics];
    }
}
