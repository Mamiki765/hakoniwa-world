<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundReceiptRollupService;
use App\Models\UndergroundProfile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class AggregateUndergroundReceipts extends Command
{
    protected $signature = 'hakoniwa:underground:receipts:aggregate
        {--profile= : Limit to one existing Underground profile ID}
        {--stream=all : battle, skip, bulk_skip, intro_request, or all}
        {--before= : Fixed cutoff, no newer than 30 days ago; defaults to 30 days ago}
        {--batch=500 : Maximum receipts per profile/stream in this invocation (1-1000)}
        {--apply : Save verified journal totals and checkpoints; never delete receipts}';

    protected $description = 'Preview or save bounded Underground journal rollups without deleting source receipts';

    public function handle(UndergroundReceiptRollupService $rollups): int
    {
        try {
            $latestCutoff = CarbonImmutable::now()->subDays(30);
            $before = $this->option('before');
            $cutoff = is_string($before) && $before !== '' ? CarbonImmutable::parse($before) : $latestCutoff;
            if ($cutoff->isAfter($latestCutoff)) {
                throw new InvalidArgumentException('--before must be at least 30 days in the past.');
            }
            $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
            if ($batch === false || $batch < 1 || $batch > 1_000) {
                throw new InvalidArgumentException('--batch must be between 1 and 1000.');
            }
            $stream = $this->option('stream');
            if (! is_string($stream) || ($stream !== 'all' && ! in_array($stream, UndergroundReceiptRollupService::STREAMS, true))) {
                throw new InvalidArgumentException('--stream must be all, battle, skip, bulk_skip or intro_request.');
            }
            $streams = $stream === 'all' ? UndergroundReceiptRollupService::STREAMS : [$stream];
            $profiles = UndergroundProfile::query()->select('id');
            $profileOption = $this->option('profile');
            if ($profileOption !== null) {
                $profileId = filter_var($profileOption, FILTER_VALIDATE_INT);
                if ($profileId === false || $profileId < 1) {
                    throw new InvalidArgumentException('--profile must be an existing positive ID.');
                }
                $profiles->whereKey($profileId);
            }
            $apply = (bool) $this->option('apply');
            $this->info(($apply ? 'APPLY' : 'PREVIEW').' cutoff='.$cutoff->toIso8601String().'; source receipts are retained.');
            $found = false;
            $blocked = false;
            foreach ($profiles->lazyById(100) as $profile) {
                $found = true;
                foreach ($streams as $selectedStream) {
                    $result = $rollups->aggregate($profile->id, $selectedStream, $cutoff, $batch, $apply);
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    $blocked = $blocked || in_array($result['stop_reason'], [
                        'unfinished_receipt', 'unclassified_activity', 'legacy_statistics_pending',
                    ], true);
                }
            }
            if (! $found && $profileOption !== null) {
                throw new InvalidArgumentException('The requested Underground profile does not exist.');
            }
            if ($blocked) {
                $this->warn('A prefix stopped at an unprepared receipt. No boundary skipped that receipt; inspect the reported stream before continuing.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
