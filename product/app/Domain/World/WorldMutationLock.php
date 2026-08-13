<?php

namespace App\Domain\World;

use App\Domain\Turn\TurnAlreadyRunningException;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorldMutationLock
{
    /** @var array<int, array{key: string, depth: int}> */
    private array $heldLocks = [];

    public function acquire(World $world): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('World mutation requires PostgreSQL advisory locks.');
        }
        if (isset($this->heldLocks[$world->id])) {
            $this->heldLocks[$world->id]['depth']++;

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
        $this->heldLocks[$world->id] = ['key' => $key, 'depth' => 1];
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

        DB::selectOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
            [$held['key']],
        );
        unset($this->heldLocks[$world->id]);
    }

    public function assertHeld(World $world): void
    {
        if (! isset($this->heldLocks[$world->id])) {
            throw new DomainException("World {$world->key} mutation lock is not held by this process.");
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
