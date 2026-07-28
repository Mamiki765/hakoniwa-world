<?php

namespace App\Console\Commands;

use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Console\Command;

final class TurnStatus extends Command
{
    protected $signature = 'hakoniwa:turn:status
                            {--world=shared-world : World key}';

    protected $description = 'Display current World turn state and recent turn runs.';

    public function handle(): int
    {
        $worldKey = (string) $this->option('world');
        $world = World::query()->where('key', $worldKey)->with('rulesetVersion')->first();
        if ($world === null) {
            $this->error("World [{$worldKey}] was not found.");

            return self::FAILURE;
        }

        $this->line(
            "world={$world->key} current_turn={$world->current_turn} "
            ."ruleset={$world->rulesetVersion->key} ruleset_version_id={$world->ruleset_version_id}",
        );
        $runs = $world->turnRuns()->latest('id')->limit(10)->get();
        $this->table(
            ['run', 'target', 'source', 'dry', 'status', 'attempts', 'started', 'completed', 'failure'],
            $runs->map(static fn (TurnRun $run): array => [
                $run->id,
                $run->target_turn,
                $run->source,
                $run->is_dry_run ? 'yes' : 'no',
                $run->status,
                $run->attempt_count,
                $run->started_at?->toIso8601String(),
                $run->completed_at?->toIso8601String(),
                $run->failure_code,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
