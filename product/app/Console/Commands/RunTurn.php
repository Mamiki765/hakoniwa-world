<?php

namespace App\Console\Commands;

use App\Application\TurnRunner;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Console\Command;
use Throwable;

final class RunTurn extends Command
{
    protected $signature = 'hakoniwa:turn:run
                            {--world=shared-world : World key}
                            {--dry-run : Record and display the pipeline without executing game phases}
                            {--source=manual : Invocation source: manual or cron}';

    protected $description = 'Run one World turn through the shared TurnRunner service.';

    public function handle(TurnRunner $runner): int
    {
        $worldKey = (string) $this->option('world');
        $source = (string) $this->option('source');
        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World [{$worldKey}] was not found.");

            return self::FAILURE;
        }

        try {
            $run = $runner->run($world, (bool) $this->option('dry-run'), $source);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("run={$run->id} world={$world->key} target_turn={$run->target_turn} status={$run->status}");
        $this->line("ruleset_version_id={$run->ruleset_version_id} seed={$run->random_seed}");
        $this->table(
            ['phase', 'required', 'implemented', 'legacy source'],
            collect($run->pipeline)->map(static fn (array $phase): array => [
                $phase['key'],
                $phase['required'] ? 'yes' : 'no',
                $phase['implemented'] ? 'yes' : 'no',
                $phase['legacy_reference'] ?? '',
            ])->all(),
        );

        if ($run->status === TurnRun::STATUS_BLOCKED) {
            $this->error((string) $run->failure_message);

            return self::FAILURE;
        }
        if ($run->status === TurnRun::STATUS_DRY_RUN) {
            $this->info('Dry-run completed; game state and current_turn were not changed.');

            return self::SUCCESS;
        }

        $this->info("World {$world->key} advanced to turn {$run->target_turn}.");

        return self::SUCCESS;
    }
}
