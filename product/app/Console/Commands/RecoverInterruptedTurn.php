<?php

namespace App\Console\Commands;

use App\Domain\World\WorldMutationLock;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoverInterruptedTurn extends Command
{
    protected $signature = 'hakoniwa:turn:recover-interrupted
                            {--world=shared-world : World key}
                            {--run= : The pending/running TurnRun id to release}
                            {--apply : Mark the run failed; without this only the checks are shown}';

    protected $description = 'Release a pending/running TurnRun left by a killed process, keeping its run and seed for a manual retry.';

    public function handle(WorldMutationLock $lock): int
    {
        $world = World::query()->where('key', (string) $this->option('world'))->first();
        $runId = filter_var($this->option('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($world === null || $runId === false) {
            $this->error('Specify an existing --world and the --run id shown by the stuck TurnRun.');

            return self::FAILURE;
        }

        try {
            // A live runner keeps this session lock for the whole turn, so
            // acquiring it proves no process is still applying this run.
            $lock->acquire($world);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $run = DB::transaction(function () use ($world, $runId): TurnRun {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $run = TurnRun::query()->whereKey($runId)->where('world_id', $world->id)
                    ->where('is_dry_run', false)->lockForUpdate()->first();
                if ($run === null) {
                    throw new DomainException("TurnRun {$runId} is not a turn of World {$world->key}.");
                }
                if (! in_array($run->status, [TurnRun::STATUS_PENDING, TurnRun::STATUS_RUNNING], true)) {
                    throw new DomainException("TurnRun {$runId} is {$run->status}, not pending or running.");
                }
                if ($run->target_turn !== $lockedWorld->current_turn + 1) {
                    throw new DomainException(
                        "TurnRun {$runId} targets turn {$run->target_turn} but World is at turn {$lockedWorld->current_turn}.",
                    );
                }
                if ($run->ruleset_version_id !== $lockedWorld->ruleset_version_id) {
                    throw new DomainException("TurnRun {$runId} was created for a different ruleset version.");
                }

                $this->line("run={$run->id} world={$world->key} target_turn={$run->target_turn} status={$run->status}");
                $this->line("attempt_count={$run->attempt_count} started_at={$run->started_at} seed={$run->random_seed}");
                if (! $this->option('apply')) {
                    return $run;
                }

                $run->update([
                    'status' => TurnRun::STATUS_FAILED,
                    'completed_at' => now(),
                    'failure_code' => 'turn_interrupted',
                    'failure_message' => 'The turn process ended without recording a result; the game transaction was rolled back.',
                    'failure_context' => [
                        ...($run->failure_context ?? []),
                        'interrupted_status' => $run->status,
                        'released_by' => 'hakoniwa:turn:recover-interrupted',
                    ],
                ]);

                return $run;
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release($world);
        }

        if (! $this->option('apply')) {
            $this->info('Checks passed. Re-run with --apply to mark this run failed. No data changed.');

            return self::SUCCESS;
        }
        $this->info("TurnRun {$run->id} is now failed with the same seed. Retry it with: "
            ."php artisan hakoniwa:turn:run --world={$world->key} --source=manual");

        return self::SUCCESS;
    }
}
