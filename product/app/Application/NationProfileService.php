<?php

namespace App\Application;

use App\Domain\Nation\NationProfileText;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class NationProfileService
{
    public function __construct(private readonly CurrentRulesetGuard $rulesetGuard) {}

    /** @param array{owner_name?: string, comment?: string} $changes */
    public function update(User $user, Nation $nation, array $changes): Nation
    {
        return DB::transaction(function () use ($user, $nation, $changes): Nation {
            $world = World::query()->whereKey($nation->world_id)->lockForUpdate()->firstOrFail();
            $ruleset = $world->rulesetVersion()->firstOrFail();
            $this->rulesetGuard->assertMutable($world, $ruleset);
            $lockedNation = Nation::query()
                ->whereKey($nation->id)
                ->where('world_id', $world->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedNation->state, ['active', 'dormant', 'recovery'], true)) {
                throw new DomainException('現役ではない島のプロフィールは変更できません。');
            }
            $this->authorize($user, $lockedNation);

            $next = [
                'owner_name' => array_key_exists('owner_name', $changes)
                    ? NationProfileText::ownerName($changes['owner_name'])
                    : $lockedNation->owner_name,
                'profile_comment' => array_key_exists('comment', $changes)
                    ? NationProfileText::comment($changes['comment'])
                    : $lockedNation->profile_comment,
            ];
            $before = [];
            $after = [];
            foreach ($next as $field => $value) {
                if ($lockedNation->{$field} === $value) {
                    continue;
                }
                $before[$field] = $lockedNation->{$field};
                $after[$field] = $value;
            }
            if ($after === []) {
                return $lockedNation;
            }

            $lockedNation->fill($after)->save();
            $occurredAt = now();
            DB::table('audit_events')->insert([
                'actor_user_id' => $user->id,
                'event_type' => 'nation.profile_updated',
                'subject_type' => Nation::class,
                'subject_id' => $lockedNation->id,
                'metadata' => json_encode([
                    'nation_id' => $lockedNation->id,
                    'nation_number' => $lockedNation->nation_number,
                    'changed_fields' => array_keys($after),
                    'before' => $before,
                    'after' => $after,
                    'actor_user_id' => $user->id,
                    'occurred_at' => $occurredAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $lockedNation->refresh();
        }, 3);
    }

    private function authorize(User $user, Nation $nation): void
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('自国のプロフィールだけを変更できます。');
        }
    }
}
