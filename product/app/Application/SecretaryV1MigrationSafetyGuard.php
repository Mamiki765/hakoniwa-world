<?php

namespace App\Application;

use App\Domain\World\WorldMutationLock;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class SecretaryV1MigrationSafetyGuard
{
    private const WORLD_KEY = 'shared-world';

    public function __construct(private WorldMutationLock $worldMutationLock) {}

    public function lockAndAssertNoUnresolvedNextTurnRun(string $operation): ?World
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Secretary v1 production migration safety requires an active transaction.');
        }

        $identity = World::query()->where('key', self::WORLD_KEY)->first(['id', 'key']);
        if ($identity === null) {
            return null;
        }

        $lock = DB::selectOne(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
            [$this->worldMutationLock->key($identity)],
        );
        if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
            throw new RuntimeException(
                "Refusing to migrate shared-world {$identity->id} ({$identity->key}) while a turn operation holds its advisory lock.",
            );
        }

        $world = World::query()->whereKey($identity->id)->lockForUpdate()
            ->first(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($world === null) {
            throw new RuntimeException('shared-world disappeared while acquiring the migration lock.');
        }

        DB::statement('LOCK TABLE turn_runs IN SHARE ROW EXCLUSIVE MODE');
        $run = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $world->current_turn + 1)
            ->unresolvedProduction()
            ->orderBy('id')
            ->first(['id', 'target_turn', 'status']);
        if ($run !== null) {
            throw new RuntimeException(
                "Refusing {$operation} with unresolved non-dry TurnRun {$run->id}, "
                ."target_turn={$run->target_turn}, status={$run->status}.",
            );
        }

        return $world;
    }
}
