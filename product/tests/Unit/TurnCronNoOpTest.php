<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NoOpTurnDatabase;
use Tests\TestCase;

final class TurnCronNoOpTest extends TestCase
{
    #[DataProvider('emptyPolls')]
    public function test_compensation_command_never_attempts_a_write_without_an_eligible_slot(bool $withBoost, string $time): void
    {
        CarbonImmutable::setTestNow($time);
        try {
            $db = NoOpTurnDatabase::install($this->app, $withBoost);
            $this->assertSame(0, Artisan::call('hakoniwa:turn:compensate'));
            $this->assertStringContainsString('No eligible compensation slot', Artisan::output());
            $this->assertNotEmpty($db->selects, 'The fixture rejects all unknown SQL and any attempt to open a PDO.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function emptyPolls(): array
    {
        return ['compensation without a reservation' => [false, '2026-11-01T19:00:00+09:00'],
            'compensation refuses even-hour slots' => [true, '2026-11-01T20:00:00+09:00'],
            'compensation refuses minutes after zero' => [true, '2026-11-01T19:01:00+09:00'],
            'compensation refuses advance when there is no debt' => [true, '2026-11-01T17:00:00+09:00']];
    }
}
