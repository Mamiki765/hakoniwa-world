<?php

namespace App\Application;

use App\Domain\Nation\NationDormancyConflictException;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ManualNationDormancyService
{
    public function __construct(
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly UserMembershipMutationLock $membershipMutationLock,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly NationLifecycleService $lifecycle,
    ) {}

    public function enter(User $user, Nation $nation, int $days): Nation
    {
        $this->authorize($user, $nation);
        $world = World::query()->findOrFail($nation->world_id);
        $this->membershipMutationLock->acquire($user);
        try {
            try {
                $this->worldMutationLock->acquire($world);
            } catch (TurnAlreadyRunningException $exception) {
                throw new NationDormancyConflictException(
                    'world_updating',
                    'このWorldは現在更新中です。後でもう一度実行してください。',
                    previous: $exception,
                );
            }

            try {
                return DB::transaction(function () use ($user, $nation, $world, $days): Nation {
                    $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                    $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                    try {
                        $this->turnRunGuard->assertClear($lockedWorld);
                    } catch (UnresolvedNextTurnRunException $exception) {
                        throw new NationDormancyConflictException(
                            'nation_dormancy_turn_unresolved',
                            '次のターン処理が未解決のため島を休止できません。',
                            previous: $exception,
                        );
                    }

                    $lockedNation = Nation::query()->whereKey($nation->id)
                        ->where('world_id', $lockedWorld->id)->lockForUpdate()->firstOrFail();
                    if ($lockedNation->state !== 'active') {
                        throw new NationDormancyConflictException(
                            'nation_not_active',
                            'この島は現在、新しい休止を申請できません。',
                        );
                    }
                    $membership = NationMembership::query()
                        ->where('user_id', $user->id)->where('world_id', $lockedWorld->id)
                        ->where('nation_id', $lockedNation->id)->where('role', 'owner')
                        ->lockForUpdate()->first();
                    if ($membership === null) {
                        throw new AuthorizationException('自分の島だけを休止できます。');
                    }

                    return $this->lifecycle->enterManual($lockedWorld, $ruleset, $lockedNation, $user, $days);
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
        if ($nation->state !== 'active') {
            throw new NationDormancyConflictException('nation_not_active', 'この島は現在、新しい休止を申請できません。');
        }
        if (! NationMembership::query()->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)->where('nation_id', $nation->id)
            ->where('role', 'owner')->exists()) {
            throw new AuthorizationException('自分の島だけを休止できます。');
        }
    }
}
