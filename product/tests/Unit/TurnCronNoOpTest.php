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
    public function test_real_cron_command_never_attempts_a_write_on_empty_polls(bool $withBoost, string $time): void
    {
        CarbonImmutable::setTestNow($time);
        try {
            $db = NoOpTurnDatabase::install($this->app, $withBoost);
            $this->assertSame(0, Artisan::call('hakoniwa:turn:run', ['--source' => 'cron']));
            $this->assertStringContainsString('No eligible turn slot', Artisan::output());
            $this->assertNotEmpty($db->selects, 'The fixture rejects all unknown SQL and any attempt to open a PDO.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function emptyPolls(): array
    {
        return ['odd hour without a boost' => [false, '2026-11-01T19:00:00+09:00'],
            'between planned slots after grace' => [true, '2026-11-01T19:16:00+09:00']];
    }
}
