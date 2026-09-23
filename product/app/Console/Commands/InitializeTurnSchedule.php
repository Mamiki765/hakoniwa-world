<?php

namespace App\Console\Commands;

use App\Domain\World\WorldMutationLock;
use App\Models\World;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class InitializeTurnSchedule extends Command
{
    protected $signature = 'hakoniwa:turn:schedule-init {--world=shared-world} {--origin= : Actual Turn 1 timestamp with timezone} {--apply : Save the reviewed origin}';

    protected $description = 'Preview or initialize an existing World turn calendar; never runs a turn.';

    public function handle(WorldMutationLock $lock): int
    {
        try {
            $text = (string) $this->option('origin');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
                throw new DomainException('Specify the actual Turn 1 origin as an ISO timestamp with timezone.');
            }
            $origin = CarbonImmutable::parse($text)->setTimezone('Asia/Tokyo');
            if ((int) $origin->format('G') % 2 !== 0 || $origin->format('i:s') !== '00:00') {
                throw new DomainException('The origin must be an even hour in JST.');
            }
            $world = World::query()->where('key', (string) $this->option('world'))->firstOrFail();
            $next = $origin->addHours($world->current_turn * (int) config('hakoniwa.turn_schedule.interval_hours', 2));
            $this->line("world={$world->key} current_turn={$world->current_turn} origin={$origin->toIso8601String()} next={$next->toIso8601String()}");
            if (! $this->option('apply')) {
                $this->info('Preview only. Use --apply after verifying the actual World calendar.');

                return self::SUCCESS;
            }
            $lock->acquire($world);
            try {
                DB::transaction(function () use ($world, $origin): void {
                    $locked = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    if ($locked->turn_schedule_origin_at !== null && ! $locked->turn_schedule_origin_at->equalTo($origin)) {
                        throw new DomainException('The calendar is already initialized; this command cannot overwrite it.');
                    }
                    $locked->update(['turn_schedule_origin_at' => $origin->utc()]);
                });
            } finally {
                $lock->release($world);
            }
            $this->info('World calendar initialized. No turn was executed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
