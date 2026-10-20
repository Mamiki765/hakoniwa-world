<?php

namespace App\Domain\Economy;

final class SellerProceedsCalculator
{
    /** Apply the caller's validated proceeds ratio with integer floor rounding. */
    public function calculate(int $amount, int $numerator, int $denominator): int
    {
        $whole = intdiv($amount, $denominator);
        $remainder = $amount % $denominator;

        return $whole * $numerator + intdiv($remainder * $numerator, $denominator);
    }
}
