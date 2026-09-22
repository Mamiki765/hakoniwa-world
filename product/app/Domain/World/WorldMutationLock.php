<?php

namespace App\Domain\World;

use App\Domain\Turn\TurnAlreadyRunningException;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

class WorldMutationLock
{
    /** @var array<int, array{key: string, depth: int, pdo: PDO}> */
    private array $heldLocks = [];

    public function acquire(World $world): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('World mutation requires PostgreSQL advisory locks.');
        }
        if (isset($this->heldLocks[$world->id])) {
            $this->assertHeld($world);
            $this->heldLocks[$world->id]['depth']++;

            return;
        }

        $key = $this->key($world);
        $row = DB::selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$key],
            false,
        );
        $acquired = $row?->acquired;
        if (! in_array($acquired, [true, 1, '1', 't'], true)) {
            throw new TurnAlreadyRunningException("World {$world->key} already has a running mutation.");
        }
        // Capture the write session after a successful acquisition, including
        // any initial reconnect performed by Laravel before acquiring the lock.
        $this->heldLocks[$world->id] = [
            'key' => $key,
            'depth' => 1,
            'pdo' => DB::connection()->getPdo(),
        ];
    }

    public function release(World $world): void
    {
        $held = $this->heldLocks[$world->id] ?? null;
        if ($held === null) {
            return;
        }
        if ($held['depth'] > 1) {
            $this->heldLocks[$world->id]['depth']--;

            return;
        }

        try {
            // Never route cleanup through a replacement session or reconnect.
            // A replaced but still live PDO must release its original lock too.
            $statement = $held['pdo']->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
            if ($statement === false || ! $statement->execute([$held['key']])) {
                throw new DomainException('World mutation lock release failed.');
            }
        } catch (PDOException $exception) {
            if (DB::connection()->getRawPdo() === $held['pdo']) {
                throw $exception;
            }
            // The original session died. Do not mask the session-loss failure
            // raised by assertHeld() with an error from releasing that dead PDO.
        } finally {
            unset($this->heldLocks[$world->id]);
        }
    }

    public function assertHeld(World $world): void
    {
        $held = $this->heldLocks[$world->id] ?? null;
        if ($held === null) {
            throw new DomainException("World {$world->key} mutation lock is not held by this process.");
        }
        if (DB::connection()->getRawPdo() !== $held['pdo']) {
            throw new DomainException("World {$world->key} mutation lock database session changed.");
        }
    }

    /**
     * The legacy key is retained so rolling deploys serialize old turn workers
     * with registration and future expansion/abandonment operations.
     */
    public function key(World $world): string
    {
        return "hakoniwa.turn.world.{$world->id}";
    }
}
