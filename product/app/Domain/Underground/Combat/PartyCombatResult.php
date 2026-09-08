<?php

namespace App\Domain\Underground\Combat;

final readonly class PartyCombatResult
{
    /**
     * @param  list<array<string, mixed>>  $actionLog
     * @param  array<string, array<string, mixed>>  $initialStates
     * @param  array<string, array<string, mixed>>  $finalStates
     * @param  array<string, int|null>  $metrics
     * @param  array<string, array<string, mixed>>  $awakening
     */
    public function __construct(
        public string $winner,
        public int $rounds,
        public array $actionLog,
        public array $initialStates,
        public array $finalStates,
        public array $metrics = [],
        public array $awakening = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['winner' => $this->winner, 'rounds' => $this->rounds, 'action_log' => $this->actionLog, 'initial_states' => $this->initialStates, 'final_states' => $this->finalStates, 'metrics' => $this->metrics, 'awakening' => $this->awakening];
    }
}
