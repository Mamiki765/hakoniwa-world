<?php

namespace App\Domain\Turn;

use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final class WorldTurnLock
{
    /** @var array<int, string> */
    private array $heldLocks = [];

    public function acquire(World $world): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('Turn execution requires PostgreSQL advisory locks.');
        }

        $key = $this->key($world);
        $row = DB::selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$key],
        );
        $acquired = $row?->acquired;
        if (! in_array($acquired, [true, 1, '1', 't'], true)) {
            throw new TurnAlreadyRunningException("World {$world->key} already has a running turn operation.");
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

    private function key(World $world): string
    {
        return "hakoniwa.turn.world.{$world->id}";
    }
}
