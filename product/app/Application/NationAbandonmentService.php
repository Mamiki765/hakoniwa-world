<?php

namespace App\Application;

use App\Domain\Nation\NationAbandonmentConfirmationException;
use App\Domain\Nation\NationAbandonmentConflictException;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Domain\World\WorldMutationLock;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class NationAbandonmentService
{
    public function __construct(
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly UserMembershipMutationLock $membershipMutationLock,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly NationAbandonmentOperation $operation,
    ) {}

    /**
     * @return array{nation_id: int, state: string, owned_cell_count: int, neutral_cleanup_cell_count: int, monster_removed_count: int, changed_chunk_count: int}
     */
    public function abandon(User $user, Nation $nation, string $confirmationName): array
    {
        $this->authorize($user, $nation);
        $world = World::query()->findOrFail($nation->world_id);

        $this->membershipMutationLock->acquire($user);
        try {
            try {
                $this->worldMutationLock->acquire($world);
            } catch (TurnAlreadyRunningException $exception) {
                throw new NationAbandonmentConflictException(
                    'world_updating',
                    'このWorldは現在更新中です。後でもう一度実行してください。',
                    previous: $exception,
                );
            }

            try {
                return DB::transaction(function () use ($user, $nation, $world, $confirmationName): array {
                    $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                    $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                    try {
                        $this->turnRunGuard->assertClear($lockedWorld);
                    } catch (UnresolvedNextTurnRunException $exception) {
                        throw new NationAbandonmentConflictException(
                            'nation_abandonment_turn_unresolved',
                            '次のターン処理が未解決のため島を破棄できません。',
                            previous: $exception,
                        );
                    }

                    $lockedNation = Nation::query()->whereKey($nation->id)
                        ->where('world_id', $lockedWorld->id)->lockForUpdate()->firstOrFail();
                    if ($lockedNation->state !== 'active') {
                        throw new NationAbandonmentConflictException(
                            'nation_not_active',
                            'この島は現在の島として破棄できません。',
                        );
                    }
                    $membership = NationMembership::query()
                        ->where('user_id', $user->id)
                        ->where('world_id', $lockedWorld->id)
                        ->where('nation_id', $lockedNation->id)
                        ->where('role', 'owner')->lockForUpdate()->first();
                    if ($membership === null) {
                        throw new AuthorizationException('自分の島だけを破棄できます。');
                    }
                    if ($confirmationName !== $lockedNation->name) {
                        throw new NationAbandonmentConfirmationException('確認用の島名が現在の島名と一致しません。');
                    }
                    $marketEscrow = AuctionListing::query()
                        ->where('world_id', $lockedWorld->id)
                        ->where('status', AuctionListing::STATUS_ACTIVE)
                        ->where(static function ($query) use ($lockedNation): void {
                            $query->where('seller_nation_id', $lockedNation->id)
                                ->orWhere('highest_bidder_nation_id', $lockedNation->id);
                        })
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                    if ($marketEscrow instanceof AuctionListing) {
                        throw new NationAbandonmentConflictException(
                            'nation_has_trading_post_escrow',
                            '交易場で出品中または最高入札中の商品があるため島を破棄できません。',
                        );
                    }

                    return $this->operation->execute(
                        $lockedWorld,
                        $ruleset,
                        $lockedNation,
                        (int) $user->id,
                        'manual',
                        (int) $lockedWorld->current_turn,
                    );
                }, 3);
            } finally {
                $this->worldMutationLock->release($world);
            }
        } finally {
            $this->membershipMutationLock->release($user);
        }
    }

    /**
     * Called only from the locked official Turn transaction.
     *
     * @return array{nation_id: int, state: string, owned_cell_count: int, neutral_cleanup_cell_count: int, monster_removed_count: int, changed_chunk_count: int}
     */
    public function abandonAutomatically(TurnContext $context, Nation $nation): array
    {
        $lockedNation = Nation::query()->whereKey($nation->id)
            ->where('world_id', $context->world->id)->lockForUpdate()->firstOrFail();
        if ($lockedNation->state !== 'dormant') {
            throw new NationAbandonmentConflictException(
                'nation_not_dormant',
                '自動破棄は休止中の島にだけ適用できます。',
            );
        }

        return $this->operation->execute(
            $context->world,
            $context->ruleset,
            $lockedNation,
            null,
            'automatic_idle',
            $context->targetTurn,
        );
    }

    private function authorize(User $user, Nation $nation): void
    {
        if ($nation->state !== 'active') {
            throw new NationAbandonmentConflictException(
                'nation_not_active',
                'この島は現在の島として破棄できません。',
            );
        }
        if (! NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')->exists()) {
            throw new AuthorizationException('自分の島だけを破棄できます。');
        }
    }
}
