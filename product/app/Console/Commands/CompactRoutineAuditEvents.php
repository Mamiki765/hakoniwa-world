<?php

namespace App\Console\Commands;

use App\Application\RoutineAuditCompactor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

final class CompactRoutineAuditEvents extends Command
{
    protected $signature = 'audit:compact-routine-events
                            {--cutoff= : Inclusive ISO-8601 occurred-at cutoff}
                            {--limit=1000 : Maximum completed Turn summaries}
                            {--max-seconds=30 : Apply time limit}
                            {--apply : Persist summary aggregation and delete exact source rows}';

    protected $description = 'Dry-run or compact an allowlist of redundant per-cell Turn audit events';

    public function handle(RoutineAuditCompactor $compactor): int
    {
        $cutoffOption = $this->option('cutoff');
        if (! is_string($cutoffOption) || trim($cutoffOption) === '') {
            $this->error('The explicit --cutoff ISO-8601 timestamp is required.');

            return self::INVALID;
        }
        try {
            $cutoff = Carbon::parse($cutoffOption);
        } catch (Throwable) {
            $this->error('The --cutoff value is not a valid timestamp.');

            return self::INVALID;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $maxSeconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 100_000
            || ! is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 3_600) {
            $this->error('limit or max-seconds is outside its safe range.');

            return self::INVALID;
        }

        $preview = $compactor->preview($cutoff, $limit);
        $this->line(sprintf(
            'mode=%s cutoff=%s summary_groups=%d event_rows=%d event_json_bytes=%d by_type=%s',
            (bool) $this->option('apply') ? 'apply' : 'dry-run',
            $cutoff->toAtomString(),
            $preview['summary_groups'],
            $preview['event_rows'],
            $preview['event_json_bytes'],
            json_encode($preview['by_type'], JSON_THROW_ON_ERROR),
        ));
        if (! (bool) $this->option('apply')) {
            $this->info('Dry-run complete; no rows were changed.');

            return self::SUCCESS;
        }

        $result = $compactor->compact($cutoff, $limit, $maxSeconds);
        $this->info(sprintf(
            'summary_groups=%d event_rows_deleted=%d stopped_by=%s by_type=%s',
            $result['summary_groups'],
            $result['event_rows_deleted'],
            $result['stopped_by'],
            json_encode($result['by_type'], JSON_THROW_ON_ERROR),
        ));

        return self::SUCCESS;
    }
}
