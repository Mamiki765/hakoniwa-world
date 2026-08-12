<?php

namespace App\Domain\World;

use App\Domain\Turn\TurnAlreadyRunningException;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorldMutationLock
{
    /** @var array<int, string> */
    private array $heldLocks = [];

    public function acquire(World $world): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('World mutation requires PostgreSQL advisory locks.');
        }
        if (isset($this->heldLocks[$world->id])) {
            return;
        }

        $key = $this->key($world);
        $row = DB::selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$key],
        );
        $acquired = $row?->acquired;
        if (! in_array($acquired, [true, 1, '1', 't'], true)) {
            throw new TurnAlreadyRunningException("World {$world->key} already has a running mutation.");
        }
        $this->heldLocks[$world->id] = $key;
    }

    public function release(World $world): void
    {
        $key = $this->heldLocks[$world->id] ?? null;
        if ($key === null) {
            return;
        }

        DB::selectOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
            [$key],
        );
        unset($this->heldLocks[$world->id]);
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
