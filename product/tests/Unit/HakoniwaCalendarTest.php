<?php

namespace Tests\Unit;

use App\Domain\World\HakoniwaCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HakoniwaCalendarTest extends TestCase
{
    #[DataProvider('turns')]
    public function test_calendar_is_derived_deterministically(int $turn, int $year, int $month): void
    {
        $calendar = (new HakoniwaCalendar)->forTurn($turn);

        $this->assertSame($year, $calendar['year']);
        $this->assertSame($month, $calendar['month']);
        $this->assertSame($calendar, (new HakoniwaCalendar)->forTurn($turn));
    }

    public static function turns(): array
    {
        return [
            'first month' => [1, 1, 1],
            'last month of first year' => [12, 1, 12],
            'first month of second year' => [13, 2, 1],
        ];
    }
}
