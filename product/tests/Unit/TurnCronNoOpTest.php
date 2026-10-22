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
    public function test_separate_automatic_commands_never_attempt_a_write_without_their_own_eligible_slot(bool $withBoost, string $time, bool $compensation): void
    {
        CarbonImmutable::setTestNow($time);
        try {
            $db = NoOpTurnDatabase::install($this->app, $withBoost);
            $this->assertSame(0, Artisan::call($compensation ? 'hakoniwa:turn:compensate' : 'hakoniwa:turn:run', $compensation ? [] : ['--source' => 'cron']));
            $this->assertStringContainsString($compensation ? 'No eligible compensation slot' : 'No eligible turn slot', Artisan::output());
            $this->assertNotEmpty($db->selects, 'The fixture rejects all unknown SQL and any attempt to open a PDO.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function emptyPolls(): array
    {
        return ['regular ignores active odd-hour compensation' => [true, '2026-11-01T19:00:00+09:00', false],
            'compensation without a reservation' => [false, '2026-11-01T19:00:00+09:00', true],
            'compensation refuses even-hour slots' => [true, '2026-11-01T20:00:00+09:00', true],
            'compensation refuses minutes after zero' => [true, '2026-11-01T19:01:00+09:00', true],
            'compensation refuses advance when there is no debt' => [true, '2026-11-01T17:00:00+09:00', true]];
    }
}
