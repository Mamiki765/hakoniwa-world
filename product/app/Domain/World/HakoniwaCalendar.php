<?php

namespace App\Domain\World;

use InvalidArgumentException;

final class HakoniwaCalendar
{
    /** @return array{year: int, month: int, label: string} */
    public function forTurn(int $turn): array
    {
        if ($turn < 1) {
            throw new InvalidArgumentException('Hakoniwa calendar requires turn 1 or later.');
        }
        $year = intdiv($turn - 1, 12) + 1;
        $month = (($turn - 1) % 12) + 1;

        return [
            'year' => $year,
            'month' => $month,
            'label' => "箱庭歴 {$year}年{$month}月",
        ];
    }
}
