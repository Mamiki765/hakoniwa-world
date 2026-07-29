<?php

namespace App\Application;

use App\Domain\Turn\TurnAlreadyAppliedException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\WorldTurnLock;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TurnRunner
{
    private const SOURCES = ['manual', 'cron'];

    public function __construct(
        private readonly TurnPipeline $pipeline,
        private readonly WorldTurnLock $lock,
        private readonly TurnSeedGenerator $seeds,
    ) {}

    public function run(World $world, bool $dryRun = false, string $source = 'manual'): TurnRun
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new DomainException('Turn source must be manual or cron.');
        }

        $this->lock->acquire($world);

        try {
            $world = World::query()->findOrFail($world->id);
            $ruleset = $world->rulesetVersion()->firstOrFail();
            $targetTurn = $world->current_turn + 1;

            if ($dryRun) {
                return $this->recordDryRun($world, $ruleset, $targetTurn, $source);
            }

            $run = $this->prepareRun($world, $ruleset, $targetTurn, $source);
            $validation = $this->pipeline->canonicalValidation();
            if (! $validation['valid']) {
                $now = now();
                $run->update([
                    'status' => TurnRun::STATUS_BLOCKED,
                    'pipeline' => $this->pipeline->snapshot(),
                    'phase_results' => [],
                    'started_at' => $now,
                    'completed_at' => $now,
                    'failure_code' => 'pipeline_invalid',
                    'failure_message' => 'Turn pipeline does not match the canonical phase contract.',
                    'failure_context' => $validation,
                ]);

                return $run->fresh();
            }

            $missing = $this->pipeline->missingRequiredPhases();
            if ($missing !== []) {
                $now = now();
                $run->update([
                    'status' => TurnRun::STATUS_BLOCKED,
                    'pipeline' => $this->pipeline->snapshot(),
                    'phase_results' => [],
                    'started_at' => $now,
                    'completed_at' => $now,
                    'failure_code' => 'pipeline_incomplete',
                    'failure_message' => 'Required turn phases are not implemented: '.implode(', ', $missing),
                    'failure_context' => ['missing_phases' => $missing],
                ]);

                return $run->fresh();
            }

            return $this->execute($world, $ruleset, $run);
        } finally {
            $this->lock->release($world);
        }
    }

    private function recordDryRun(
        World $world,
        RulesetVersion $ruleset,
        int $targetTurn,
        string $source,
    ): TurnRun {
        $now = now();

        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $this->seeds->generate($world, $targetTurn, $ruleset),
            'source' => $source,
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => $this->pipeline->snapshot(),
            'phase_results' => [],
            'started_at' => $now,
            'completed_at' => $now,
            'failure_context' => [
                'missing_phases' => $this->pipeline->missingRequiredPhases(),
                'pipeline_validation' => $this->pipeline->canonicalValidation(),
            ],
        ]);
    }

    private function prepareRun(
        World $world,
        RulesetVersion $ruleset,
        int $targetTurn,
        string $source,
    ): TurnRun {
        $run = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $targetTurn)
            ->where('is_dry_run', false)
            ->first();

        if ($run === null) {
            return TurnRun::query()->create([
                'world_id' => $world->id,
                'target_turn' => $targetTurn,
                'ruleset_version_id' => $ruleset->id,
                'random_seed' => $this->seeds->generate($world, $targetTurn, $ruleset),
                'source' => $source,
                'is_dry_run' => false,
                'status' => TurnRun::STATUS_PENDING,
                'attempt_count' => 1,
                'pipeline' => $this->pipeline->snapshot(),
                'phase_results' => [],
                'failure_context' => [],
            ]);
        }

        if ($run->status === TurnRun::STATUS_COMPLETED) {
            throw new TurnAlreadyAppliedException(
                "World {$world->key} turn {$targetTurn} has already been applied.",
            );
        }
        if (in_array($run->status, [TurnRun::STATUS_PENDING, TurnRun::STATUS_RUNNING], true)) {
            throw new TurnAlreadyRunningException(
                "World {$world->key} turn {$targetTurn} already has a {$run->status} run.",
            );
        }
        if ($run->ruleset_version_id !== $ruleset->id) {
            throw new DomainException('A failed turn cannot be retried after the World ruleset snapshot changes.');
        }

        $run->update([
            'source' => $source,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => $run->attempt_count + 1,
            'pipeline' => $this->pipeline->snapshot(),
            'phase_results' => [],
            'started_at' => null,
            'completed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
            'failure_context' => [],
        ]);

        return $run->fresh();
    }

    private function execute(World $world, RulesetVersion $ruleset, TurnRun $run): TurnRun
    {
        $run->update([
            'status' => TurnRun::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
        ]);
        $currentPhase = null;

        try {
            DB::transaction(function () use ($world, $ruleset, $run, &$currentPhase): void {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                if ($lockedWorld->current_turn + 1 !== $run->target_turn) {
                    throw new TurnAlreadyAppliedException('World current_turn no longer matches the turn run target.');
                }
                if ($lockedWorld->ruleset_version_id !== $run->ruleset_version_id) {
                    throw new DomainException('World ruleset changed after the turn run snapshot was created.');
                }

                $context = new TurnContext(
                    world: $lockedWorld,
                    run: $run,
                    ruleset: $ruleset,
                    targetTurn: $run->target_turn,
                    randomSeed: $run->random_seed,
                );
                $results = [];
                foreach ($this->pipeline->phases() as $phase) {
                    $currentPhase = $phase->key();
                    $started = hrtime(true);
                    $result = $phase->execute($context);
                    if ($result->phase !== $phase->key()) {
                        throw new DomainException("Turn phase {$phase->key()} returned a mismatched result.");
                    }
                    $results[] = [
                        ...$result->toArray(),
                        'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
                    ];
                }

                $lockedWorld->update(['current_turn' => $run->target_turn]);
                TurnRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail()->update([
                    'status' => TurnRun::STATUS_COMPLETED,
                    'phase_results' => $results,
                    'completed_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                    'failure_context' => [],
                ]);
            }, 1);
        } catch (Throwable $exception) {
            TurnRun::query()->whereKey($run->id)->update([
                'status' => TurnRun::STATUS_FAILED,
                'completed_at' => now(),
                'failure_code' => 'turn_execution_failed',
                'failure_message' => Str::limit($exception->getMessage(), 2_000, ''),
                'failure_context' => [
                    'phase' => $currentPhase,
                    'exception_class' => $exception::class,
                ],
            ]);

            throw $exception;
        }

        $completedRun = $run->fresh();
        if (! $completedRun instanceof TurnRun) {
            throw new RuntimeException(
                "Turn run {$run->id} disappeared before post-commit state could be confirmed.",
            );
        }

        return $completedRun;
    }
}
