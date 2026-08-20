<?php

namespace App\Domain\Nation;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserMembershipMutationLock
{
    /** @var array<int, array{key: string, depth: int}> */
    private array $heldLocks = [];

    public function acquire(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('User membership mutation requires PostgreSQL advisory locks.');
        }
        if (isset($this->heldLocks[$userId])) {
            $this->heldLocks[$userId]['depth']++;

            return;
        }

        $key = $this->key($userId);
        DB::selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        $this->heldLocks[$userId] = ['key' => $key, 'depth' => 1];
    }

    public function release(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $held = $this->heldLocks[$userId] ?? null;
        if ($held === null) {
            return;
        }
        if ($held['depth'] > 1) {
            $this->heldLocks[$userId]['depth']--;

            return;
        }

        DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released', [$held['key']]);
        unset($this->heldLocks[$userId]);
    }

    public function assertHeld(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! isset($this->heldLocks[$userId])) {
            throw new DomainException("User {$userId} membership mutation lock is not held by this process.");
        }
    }

    public function key(User|int $user): string
    {
        $userId = $user instanceof User ? $user->id : $user;

        return "hakoniwa.membership.user.{$userId}";
    }
}
