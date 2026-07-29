<?php

namespace App\Domain\Economy;

final readonly class NationCapacities
{
    public function __construct(
        public int $money,
        public int $foodTons,
    ) {}
}
