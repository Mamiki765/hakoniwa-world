<?php

namespace App\Domain\Economy;

final readonly class NationCapacities
{
    /** @param array<string, int> $resources */
    public function __construct(
        public int $money,
        public int $foodTons,
        public array $resources,
    ) {}

    public function resource(string $key): ?int
    {
        return $this->resources[$key] ?? null;
    }
}
