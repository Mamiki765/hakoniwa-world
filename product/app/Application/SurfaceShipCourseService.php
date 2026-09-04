<?php

namespace App\Application;

use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Ship;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SurfaceShipCourseService
{
    public function __construct(
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly UserMembershipMutationLock $membershipMutationLock,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
    ) {}

    public function update(
        User $user,
        Nation $nation,
        Ship $ship,
        ?int $heading,
        int $expectedVersion,
    ): Ship {
        $this->authorize($user, $nation);
        if ($heading !== null && ($heading < 0 || $heading > 5)) {
            throw new DomainException('進路はrandomまたは6方向から選択してください。');
        }
        $world = World::query()->findOrFail($nation->world_id);
        $this->membershipMutationLock->acquire($user);
        try {
            $this->worldMutationLock->acquire($world);
            try {
                return DB::transaction(function () use ($user, $nation, $ship, $heading, $expectedVersion, $world): Ship {
                    $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                    $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                    $this->turnRunGuard->assertClear($lockedWorld);
                    $lockedNation = Nation::query()->whereKey($nation->id)
                        ->where('world_id', $lockedWorld->id)->lockForUpdate()->firstOrFail();
                    if ($lockedNation->state !== 'active') {
                        throw new DomainException('現役の島だけが船の進路を変更できます。');
                    }
                    $this->authorizeLocked($user, $lockedNation);
                    $lockedShip = Ship::query()->whereKey($ship->id)
                        ->where('world_id', $lockedWorld->id)
                        ->where('nation_id', $lockedNation->id)
                        ->where('state', Ship::STATE_ACTIVE)
                        ->lockForUpdate()->first();
                    if (! $lockedShip instanceof Ship) {
                        throw new DomainException('自国の現役Shipを選択してください。');
                    }
                    if ((int) $lockedShip->version !== $expectedVersion) {
                        throw new OptimisticLockException('Shipが他の操作で更新されました。再読込してください。');
                    }
                    if ($lockedShip->heading === $heading) {
                        return $lockedShip;
                    }
                    $before = $lockedShip->heading;
                    $lockedShip->heading = $heading;
                    $lockedShip->version++;
                    $lockedShip->save();
                    DB::table('audit_events')->insert([
                        'actor_user_id' => $user->id,
                        'event_type' => 'ship.heading.updated',
                        'subject_type' => Ship::class,
                        'subject_id' => $lockedShip->id,
                        'metadata' => json_encode([
                            'nation_id' => (int) $lockedNation->id,
                            'before' => $before,
                            'after' => $heading,
                        ], JSON_THROW_ON_ERROR),
                        'occurred_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $lockedShip;
                }, 3);
            } finally {
                $this->worldMutationLock->release($world);
            }
        } finally {
            $this->membershipMutationLock->release($user);
        }
    }

    private function authorize(User $user, Nation $nation): void
    {
        if (! NationMembership::query()->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)->where('nation_id', $nation->id)
            ->where('role', 'owner')->exists()) {
            throw new AuthorizationException('自国のShipだけを操作できます。');
        }
    }

    private function authorizeLocked(User $user, Nation $nation): void
    {
        $membership = NationMembership::query()->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)->where('nation_id', $nation->id)
            ->where('role', 'owner')->lockForUpdate()->first();
        if (! $membership instanceof NationMembership) {
            throw new AuthorizationException('自国のShipだけを操作できます。');
        }
    }
}
