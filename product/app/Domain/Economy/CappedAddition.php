<?php

namespace App\Domain\Economy;

use DomainException;

final class CappedAddition
{
    public function calculate(int $before, int $requested, int $capacity): CapacityAdditionResult
    {
        if ($before < 0 || $requested < 0 || $capacity < 0) {
            throw new DomainException('Capacity additions require non-negative integer values.');
        }

        $remaining = max(0, $capacity - $before);
        $applied = min($requested, $remaining);

        return new CapacityAdditionResult(
            before: $before,
            requested: $requested,
            applied: $applied,
            overflow: $requested - $applied,
            after: $before + $applied,
            capacity: $capacity,
        );
    }
}
