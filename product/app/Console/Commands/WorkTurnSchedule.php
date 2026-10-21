<?php

namespace App\Console\Commands;

use App\Application\TurnRunner;
use App\Models\World;
use Illuminate\Console\Command;
use Throwable;

final class WorkTurnSchedule extends Command
{
    protected $signature = 'hakoniwa:turn:work {--world=shared-world} {--once : Poll once and exit}';

    protected $description = 'Poll persistent turn slots without cron; execute at most one current slot per poll.';

    public function handle(TurnRunner $runner): int
    {
        do {
            try {
                $world = World::query()->where('key', (string) $this->option('world'))->firstOrFail();
                $run = $runner->run($world, source: 'scheduler');
                if ($run !== null) {
                    $this->line("run={$run->id} status={$run->status}");
                }
            } catch (Throwable $exception) {
                // Supervision may restart this process, but never resumes an unresolved run.
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            if ($this->option('once')) {
                return self::SUCCESS;
            }
            sleep(10);
        } while (true);
    }
}
