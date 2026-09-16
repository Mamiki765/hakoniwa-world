<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundRuntimeService;
use Illuminate\Console\Command;

final class PruneUndergroundBattleLogs extends Command
{
    protected $signature = 'underground:prune-battle-logs
                            {--batch=1000 : Rows per log/image-reference batch}
                            {--max-batches=20 : Maximum cleanup batches}
                            {--max-seconds=30 : Maximum execution time}';

    protected $description = 'Delete expired Underground battle action logs while retaining summaries and idempotency records';

    public function handle(UndergroundRuntimeService $runtime): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $maxBatches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT);
        $maxSeconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if (! is_int($batch) || ! is_int($maxBatches) || ! is_int($maxSeconds)) {
            $this->error('Cleanup options must be integers.');

            return self::INVALID;
        }
        try {
            $result = $runtime->pruneExpiredBattleData($batch, $maxBatches, $maxSeconds);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->info(sprintf(
            'logs=%d->%d deleted=%d oldest_before=%s oldest_after=%s images=%d->%d image_refs_deleted=%d image_oldest_before=%s image_oldest_after=%s batches=%d stopped_by=%s',
            $result['logs_before'],
            $result['logs_after'],
            $result['logs_deleted'],
            $result['oldest_log_before'] ?? 'none',
            $result['oldest_log_after'] ?? 'none',
            $result['image_references_before'],
            $result['image_references_after'],
            $result['image_references_deleted'],
            $result['oldest_image_before'] ?? 'none',
            $result['oldest_image_after'] ?? 'none',
            $result['batches'],
            $result['stopped_by'],
        ));

        return self::SUCCESS;
    }
}
