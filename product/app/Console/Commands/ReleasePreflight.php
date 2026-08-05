<?php

namespace App\Console\Commands;

use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Console\Command;

final class ReleasePreflight extends Command
{
    protected $signature = 'hakoniwa:release:preflight
                            {--world=shared-world : World key}';

    protected $description = 'Fail when the next production TurnRun is unresolved before deploy.';

    public function handle(): int
    {
        $contactUrl = config('hakoniwa.community.contact_url');
        $scheme = is_string($contactUrl) ? parse_url($contactUrl, PHP_URL_SCHEME) : null;
        if (filter_var($contactUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            $this->error('Deploy blocked: HAKONIWA_MODERATION_CONTACT_URL must be an absolute HTTP(S) URL.');

            return self::FAILURE;
        }

        $worldKey = (string) $this->option('world');
        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World [{$worldKey}] was not found.");

            return self::FAILURE;
        }

        $targetTurn = (int) $world->current_turn + 1;
        $unsafe = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $targetTurn)
            ->where('is_dry_run', false)
            ->whereIn('status', [
                TurnRun::STATUS_PENDING,
                TurnRun::STATUS_RUNNING,
                TurnRun::STATUS_FAILED,
            ])
            ->orderBy('id')
            ->get(['id', 'status', 'attempt_count']);
        if ($unsafe->isNotEmpty()) {
            $this->error("Deploy blocked: target turn {$targetTurn} has unresolved production TurnRun state.");
            $this->table(['run', 'status', 'attempts'], $unsafe->map(
                static fn (TurnRun $run): array => [$run->id, $run->status, $run->attempt_count],
            )->all());

            return self::FAILURE;
        }

        $this->info("release_preflight=ok world={$world->key} next_target_turn={$targetTurn}");

        return self::SUCCESS;
    }
}
