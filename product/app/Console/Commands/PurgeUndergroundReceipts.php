<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundReceiptPurgeService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Models\UndergroundProfile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class PurgeUndergroundReceipts extends Command
{
    protected $signature = 'hakoniwa:underground:receipts:purge
        {--profile= : Limit to one existing Underground profile ID}
        {--stream=all : battle, skip, bulk_skip, intro_request, or all}
        {--before= : Fixed cutoff, at least 30 days ago; defaults to 30 days ago}
        {--batch=500 : Maximum receipts per profile/stream in this invocation (1-1000)}
        {--apply : DELETE only verified, durable, unpinned receipt prefixes}';

    protected $description = 'Read-only Underground receipt retention status by default; manual --apply deletes a bounded verified prefix';

    public function handle(UndergroundReceiptPurgeService $purge): int
    {
        try {
            $latestCutoff = CarbonImmutable::now()->subDays(30);
            $before = $this->option('before');
            $cutoff = is_string($before) && $before !== '' ? CarbonImmutable::parse($before) : $latestCutoff;
            $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
            if ($cutoff->isAfter($latestCutoff) || $batch === false || $batch < 1 || $batch > 1_000) {
                throw new InvalidArgumentException('Specify --before at least 30 days ago and --batch between 1 and 1000.');
            }
            $stream = $this->option('stream');
            if (! is_string($stream) || ($stream !== 'all' && ! in_array($stream, UndergroundReceiptRollupService::STREAMS, true))) {
                throw new InvalidArgumentException('Unknown receipt stream.');
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
            $this->info(($apply ? 'APPLY' : 'PREVIEW (read-only)').' cutoff='.$cutoff->toIso8601String());
            $found = false;
            $blocked = false;
            foreach ($profiles->lazyById(100) as $profile) {
                $found = true;
                foreach ($streams as $selectedStream) {
                    $result = $purge->purge($profile->id, $selectedStream, $cutoff, $batch, $apply);
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $blocked = $blocked || ! in_array($result['stop_reason'], ['batch_limit', 'no_more_receipts', 'retention_window'], true);
                }
            }
            if (! $found && $profileOption !== null) {
                throw new InvalidArgumentException('The requested Underground profile does not exist.');
            }
            if ($blocked) {
                $this->warn('A prefix is blocked; inspect stop_reason. Unverified receipts require aggregate preview/apply first.');
            }

            return $apply && $blocked ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
