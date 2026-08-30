<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundRuntimeService;
use Illuminate\Console\Command;

final class PruneUndergroundBattleLogs extends Command
{
    protected $signature = 'underground:prune-battle-logs';

    protected $description = 'Delete expired Underground battle action logs while retaining summaries and idempotency records';

    public function handle(UndergroundRuntimeService $runtime): int
    {
        $deleted = $runtime->pruneExpiredBattleLogs();
        $this->info("Pruned {$deleted} expired Underground battle log(s).");

        return self::SUCCESS;
    }
}
