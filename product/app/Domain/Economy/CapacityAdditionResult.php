<?php

namespace App\Domain\Economy;

final readonly class CapacityAdditionResult
{
    public function __construct(
        public int $before,
        public int $requested,
        public int $applied,
        public int $overflow,
        public int $after,
        public int $capacity,
    ) {}

    /** @return array{before: int, requested: int, applied: int, overflow: int, after: int, capacity: int} */
    public function toArray(): array
    {
        return [
            'before' => $this->before,
            'requested' => $this->requested,
            'applied' => $this->applied,
            'overflow' => $this->overflow,
            'after' => $this->after,
            'capacity' => $this->capacity,
        ];
    }
}
