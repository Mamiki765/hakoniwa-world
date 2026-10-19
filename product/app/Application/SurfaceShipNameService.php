<?php

namespace App\Application;

use App\Domain\Nation\GivenName;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Ship;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

// A Ship name is display text only; it never reaches movement, combat or RNG.
final class SurfaceShipNameService
{
    public function __construct(private readonly CurrentRulesetGuard $rulesetGuard) {}

    public function rename(User $user, Nation $nation, Ship $ship, ?string $name): Ship
    {
        return DB::transaction(function () use ($user, $nation, $ship, $name): Ship {
            $world = World::query()->whereKey($nation->world_id)->lockForUpdate()->firstOrFail();
            $this->rulesetGuard->assertMutable($world, $world->rulesetVersion()->firstOrFail());
            $lockedNation = Nation::query()->whereKey($nation->id)
                ->where('world_id', $world->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedNation->state, ['active', 'dormant', 'recovery'], true)) {
                throw new DomainException('現役ではない島の船には名前をつけられません。');
            }
            if (! NationMembership::query()->where('user_id', $user->id)
                ->where('world_id', $world->id)->where('nation_id', $lockedNation->id)
                ->where('role', 'owner')->lockForUpdate()->exists()) {
                throw new AuthorizationException('自国の船だけに名前をつけられます。');
            }
            $lockedShip = Ship::query()->whereKey($ship->id)
                ->where('world_id', $world->id)
                ->where('nation_id', $lockedNation->id)
                ->where('state', Ship::STATE_ACTIVE)
                ->lockForUpdate()->first();
            if (! $lockedShip instanceof Ship) {
                throw new DomainException('自国の現役の船を選択してください。');
            }
            $next = GivenName::normalize($name, '船の名前');
            if ($lockedShip->custom_name === $next) {
                return $lockedShip;
            }
            $before = $lockedShip->custom_name;
            $lockedShip->custom_name = $next;
            $lockedShip->save();
            $occurredAt = now();
            DB::table('audit_events')->insert([
                'actor_user_id' => $user->id,
                'world_id' => $world->id,
                'turn' => $world->current_turn,
                'nation_id' => $lockedNation->id,
                'message' => null,
                'visibility' => 'admin',
                'event_type' => 'ship.name.updated',
                'severity' => 'info',
                'subject_type' => Ship::class,
                'subject_id' => $lockedShip->id,
                'metadata' => json_encode([
                    'nation_id' => (int) $lockedNation->id,
                    'before' => $before,
                    'after' => $next,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $lockedShip;
        }, 3);
    }
}
