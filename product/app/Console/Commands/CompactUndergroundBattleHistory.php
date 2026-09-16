<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundBattleHistoryCompactor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

final class CompactUndergroundBattleHistory extends Command
{
    protected $signature = 'underground:compact-battle-history
                            {--cutoff= : Inclusive ISO-8601 finished-at cutoff}
                            {--limit=1000 : Maximum battles to inspect or compact}
                            {--batch=100 : Candidate batch size}
                            {--max-seconds=30 : Soft apply budget checked between battle transactions}
                            {--apply : Persist statistics rescue and snapshot compaction}';

    protected $description = 'Dry-run or compact expired Underground battle snapshots in bounded, resumable batches';

    public function handle(UndergroundBattleHistoryCompactor $compactor): int
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
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $maxSeconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 100_000
            || ! is_int($batch) || $batch < 1 || $batch > 1_000
            || ! is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 3_600) {
            $this->error('limit, batch, or max-seconds is outside its safe range.');

            return self::INVALID;
        }

        $preview = $compactor->preview($cutoff, $limit, $batch);
        $this->line(sprintf(
            'mode=%s cutoff=%s preview_batch=%d apply_time_budget=soft_between_battles candidates=%d battle_json_bytes=%d member_json_bytes=%d self_damage_backfillable=%d self_damage_null=%d damage_source_breakdown_null=%d healing_source_breakdown_null=%d oldest=%s newest=%s',
            (bool) $this->option('apply') ? 'apply' : 'dry-run',
            $cutoff->toAtomString(),
            $batch,
            $preview['candidates'],
            $preview['battle_bytes'],
            $preview['member_bytes'],
            $preview['self_damage_backfillable'],
            $preview['self_damage_null'],
            $preview['damage_source_breakdown_null'],
            $preview['healing_source_breakdown_null'],
            $preview['oldest_finished_at'] ?? 'none',
            $preview['newest_finished_at'] ?? 'none',
        ));
        if (! (bool) $this->option('apply')) {
            $this->info('Dry-run complete; no rows were changed.');

            return self::SUCCESS;
        }

        $result = $compactor->compact($cutoff, $limit, $batch, $maxSeconds);
        $this->info(sprintf(
            'processed=%d statistics_backfilled=%d battle_bytes=%d->%d member_bytes=%d->%d stopped_by=%s',
            $result['processed'],
            $result['statistics_backfilled'],
            $result['battle_bytes_before'],
            $result['battle_bytes_after'],
            $result['member_bytes_before'],
            $result['member_bytes_after'],
            $result['stopped_by'],
        ));

        return self::SUCCESS;
    }
}
