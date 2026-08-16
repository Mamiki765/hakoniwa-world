<?php

namespace Tests\Unit;

use App\Domain\World\HakoniwaCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HakoniwaCalendarTest extends TestCase
{
    #[DataProvider('turns')]
    public function test_calendar_is_derived_deterministically(int $turn, int $year, int $month): void
    {
        $this->assertSame([
            'year' => $year,
            'month' => $month,
            'label' => "箱庭歴 {$year}年{$month}月",
        ], (new HakoniwaCalendar)->forTurn($turn));
    }

    public function test_turn_zero_has_no_implicit_calendar_meaning(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HakoniwaCalendar)->forTurn(0);
    }

    public static function turns(): array
    {
        return [
            'first month' => [1, 1, 1],
            'last month of first year' => [12, 1, 12],
            'first month of second year' => [13, 2, 1],
            'large deterministic value' => [120_001, 10_001, 1],
        ];
    }
}
