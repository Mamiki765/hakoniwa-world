<?php

namespace App\Application;

use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnAlreadyAppliedException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\TurnState;
use App\Domain\World\WorldMutationLock;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class TurnRunner
{
    private const SOURCES = ['manual', 'cron'];

    private const MAX_TRANSIENT_ATTEMPTS = 3;

    public function __construct(
        private readonly TurnPipeline $pipeline,
        private readonly WorldMutationLock $lock,
        private readonly TurnSeedGenerator $seeds,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly MonsterKillCycleService $monsterCycles,
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
            $this->rulesetGuard->assertMutable($world, $ruleset);
            $targetTurn = $world->current_turn + 1;

            if ($dryRun) {
                return $this->recordDryRun($world, $ruleset, $targetTurn, $source);
            }

            $this->monsterCycles->assertLegacySeedCoverage($world, $targetTurn);
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
        if ($source === 'cron' && in_array($run->status, [
            TurnRun::STATUS_FAILED,
            TurnRun::STATUS_BLOCKED,
        ], true)) {
            throw new DomainException(
                "Cron cannot retry unresolved World {$world->key} turn {$targetTurn}; "
                .'use source=manual after operator review.',
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
        $attempt = 0;
        $retries = [];

        try {
            while (true) {
                $attempt++;
                $currentPhase = null;
                $bodyFailure = null;
                $connection = DB::connection();
                $entryLevel = $connection->transactionLevel();
                $pdo = $connection->getPdo();
                // This singleton is shared by command, monster and disaster paths.
                // Database rollback cannot restore its occupancy index or counters.
                app(MonsterRemovalService::class)->resetForAttempt();

                try {
                    DB::transaction(function () use ($world, $ruleset, $run, $retries, &$currentPhase, &$bodyFailure): void {
                        try {
                            $this->executeAttempt($world, $ruleset, $run, $retries, $currentPhase);
                        } catch (Throwable $exception) {
                            $bodyFailure = $exception;
                            throw $exception;
                        }
                    }, 1);
                    break;
                } catch (Throwable $exception) {
                    // The callback captures this by reference; it can change
                    // before Connection::transaction() rethrows the exception.
                    /** @var Throwable|null $bodyFailure */
                    $sqlState = $this->sqlState($exception);
                    // Only retry a body failure after a complete root rollback on
                    // the same session. Commit/after-commit and reconnect failures
                    // do not establish that all game effects were rolled back.
                    if ($attempt >= self::MAX_TRANSIENT_ATTEMPTS
                        || ! in_array($sqlState, ['40P01', '40001'], true)
                        || $bodyFailure !== $exception
                        || ! $this->rolledBackOnSameSession($connection, $pdo, $entryLevel)) {
                        throw $exception;
                    }

                    $retries[] = [
                        'attempt_count' => $run->attempt_count,
                        'phase' => $currentPhase,
                        'sqlstate' => $sqlState,
                    ];
                    // Persist the attempt counter outside the rolled-back game
                    // transaction, retaining the same run, ruleset and seed.
                    $run->refresh();
                    $run->update([
                        'attempt_count' => $run->attempt_count + 1,
                        'failure_context' => ['transient_retries' => $retries],
                    ]);
                }
            }
        } catch (Throwable $exception) {
            // An after-commit failure must not relabel a committed turn as failed.
            TurnRun::query()->whereKey($run->id)->where('status', TurnRun::STATUS_RUNNING)->update([
                'status' => TurnRun::STATUS_FAILED,
                'completed_at' => now(),
                'failure_code' => 'turn_execution_failed',
                'failure_message' => Str::limit($exception->getMessage(), 2_000, ''),
                'failure_context' => [
                    'phase' => $currentPhase,
                    'exception_class' => $exception::class,
                    'sqlstate' => $this->sqlState($exception),
                    'attempts_this_invocation' => $attempt,
                    'transient_retries' => $retries,
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

    private function rolledBackOnSameSession(Connection $connection, PDO $pdo, int $entryLevel): bool
    {
        // These are post-transaction observations, not memoized entry values.
        return $connection->getDriverName() === 'pgsql'
            && $entryLevel === 0
            && $connection->transactionLevel() === 0
            && $connection->getPdo() === $pdo
            && ! $pdo->inTransaction();
    }

    /** @param list<array{attempt_count: int, phase: string|null, sqlstate: string|null}> $retries */
    private function executeAttempt(
        World $world,
        RulesetVersion $ruleset,
        TurnRun $run,
        array $retries,
        ?string &$currentPhase,
    ): void {
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
            random: new TurnRandomStreamFactory($run->random_seed),
            state: new TurnState,
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
            'failure_context' => $retries === [] ? [] : ['transient_retries' => $retries],
        ]);
    }

    private function sqlState(Throwable $exception): ?string
    {
        // Laravel wraps nested transaction conflicts in DeadlockException.
        // Follow the cause to the first structured PDO error; never infer a
        // retry from the translated message or an unrelated exception code.
        do {
            if ($exception instanceof PDOException) {
                $state = $exception->errorInfo[0] ?? $exception->getCode();
                if (is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) === 1) {
                    return $state;
                }
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }
}
