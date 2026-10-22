<?php

namespace App\Console\Commands;

use App\Application\TurnRunner;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Console\Command;
use Throwable;

final class CompensateTurn extends Command
{
    protected $signature = 'hakoniwa:turn:compensate {--world=shared-world : World key}';

    protected $description = 'Run at most one reserved disaster-compensation turn at an odd JST hour; never run a regular turn.';

    public function handle(TurnRunner $runner): int
    {
        $world = World::query()->where('key', (string) $this->option('world'))->first();
        if ($world === null) {
            $this->error('World was not found.');

            return self::FAILURE;
        }
        try {
            $run = $runner->run($world, source: 'compensation');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($run === null) {
            $this->info('No eligible compensation slot. No game state changed.');

            return self::SUCCESS;
        }
        $this->line("run={$run->id} world={$world->key} target_turn={$run->target_turn} status={$run->status}");

        return $run->status === TurnRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
