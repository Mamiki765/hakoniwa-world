<?php

namespace App\Console\Commands;

use App\Application\CurrentDatabaseBaseline;
use Illuminate\Console\Command;
use Throwable;

final class CheckDatabaseBaseline extends Command
{
    protected $signature = 'hakoniwa:baseline:check';

    protected $description = 'Read-only check of schema, migration ledger and the accepted current Ruleset';

    public function handle(CurrentDatabaseBaseline $baseline): int
    {
        try {
            $baseline->assertExisting();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('The database matches the accepted 4.9.0 baseline. No business data or migration ledger was changed.');

        return self::SUCCESS;
    }
}
