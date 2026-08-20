<?php

namespace App\Application;

use App\Domain\Map\MapCellStateService;
use App\Domain\Nation\NationAbandonmentConfirmationException;
use App\Domain\Nation\NationAbandonmentConflictException;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Domain\World\WorldMutationLock;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NationAbandonmentService
{
    public function __construct(
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly UserMembershipMutationLock $membershipMutationLock,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly MapCellStateService $cellStates,
        private readonly MonsterRemovalService $monsterRemoval,
    ) {}

    /**
     * @return array{
     *     nation_id: int,
     *     state: string,
     *     owned_cell_count: int,
     *     neutral_cleanup_cell_count: int,
     *     monster_removed_count: int,
     *     changed_chunk_count: int
     * }
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

                    $lockedNation = Nation::query()
                        ->whereKey($nation->id)
                        ->where('world_id', $lockedWorld->id)
                        ->lockForUpdate()
                        ->firstOrFail();
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
                        ->where('role', 'owner')
                        ->lockForUpdate()
                        ->first();
                    if ($membership === null) {
                        throw new AuthorizationException('自分の島だけを破棄できます。');
                    }
                    if ($confirmationName !== $lockedNation->name) {
                        throw new NationAbandonmentConfirmationException('確認用の島名が現在の島名と一致しません。');
                    }

                    $capital = NationCapital::query()
                        ->where('nation_id', $lockedNation->id)
                        ->lockForUpdate()
                        ->first();
                    if ($capital === null) {
                        throw new DomainException('Active Nation is missing its Capital.');
                    }
                    $oldCapital = [
                        'map_cell_id' => (int) $capital->map_cell_id,
                        'x' => (int) $capital->x,
                        'y' => (int) $capital->y,
                    ];

                    $surface = MapSpace::query()
                        ->where('world_id', $lockedWorld->id)
                        ->where('key', 'surface')
                        ->lockForUpdate()
                        ->firstOrFail();
                    $radius = $ruleset->settings['initial_island_reservation_radius'] ?? null;
                    if (! is_int($radius) || $radius < 0) {
                        throw new DomainException('The current ruleset has an invalid initial island reservation radius.');
                    }
                    $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
                    $capitalCubeX = $oldCapital['x'] - (int) floor(($oldCapital['y'] + 1) / 2);
                    $capitalCubeSum = $capitalCubeX + $oldCapital['y'];

                    $cells = MapCell::query()
                        ->where('map_space_id', $surface->id)
                        ->where(function (Builder $scope) use ($lockedNation, $radius, $capitalCubeX, $oldCapital, $capitalCubeSum): void {
                            $scope->where('owner_nation_id', $lockedNation->id)
                                ->orWhere(function (Builder $neutral) use ($radius, $capitalCubeX, $oldCapital, $capitalCubeSum): void {
                                    $neutral->whereNull('owner_nation_id')
                                        ->whereRaw(<<<'SQL'
GREATEST(
    ABS((x - FLOOR((y + 1) / 2.0)) - ?),
    ABS(y - ?),
    ABS(((x - FLOOR((y + 1) / 2.0)) + y) - ?)
) <= ?
SQL, [$capitalCubeX, $oldCapital['y'], $capitalCubeSum, $radius]);
                                });
                        })
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $ownedCellCount = $cells->where('owner_nation_id', $lockedNation->id)->count();
                    $neutralCleanupCellCount = $cells->whereNull('owner_nation_id')->count();
                    $chunkIds = $cells->pluck('map_chunk_id')->unique()->sort()->values()->all();
                    $chunks = $chunkIds === []
                        ? collect()
                        : MapChunk::query()->whereIn('id', $chunkIds)->orderBy('id')->lockForUpdate()->get();
                    $occupancies = $cells->isEmpty()
                        ? collect()
                        : MonsterOccupancy::query()
                            ->whereIn('map_cell_id', $cells->modelKeys())
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                    $monsterRemovedCount = 0;
                    foreach ($occupancies as $occupancy) {
                        if ($this->monsterRemoval->removeForWorldMutation($occupancy, 'nation_abandoned')) {
                            $monsterRemovedCount++;
                        }
                    }

                    foreach ($cells as $cell) {
                        $this->cellStates->setFacility($cell, null);
                        $this->cellStates->transitionTerrain($cell, $sea);
                        $cell->owner_nation_id = null;
                        $cell->population = 0;
                        $cell->version++;
                        $cell->save();
                    }
                    foreach ($chunks as $chunk) {
                        $chunk->version++;
                        $chunk->save();
                    }

                    $queue = NationCommandQueue::query()
                        ->where('nation_id', $lockedNation->id)
                        ->lockForUpdate()
                        ->first();
                    if ($queue !== null) {
                        NationCommandQueueItem::query()
                            ->where('nation_command_queue_id', $queue->id)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                        $queue->delete();
                    }
                    $resources = NationResource::query()
                        ->where('nation_id', $lockedNation->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    foreach ($resources as $resource) {
                        $resource->amount = 0;
                        $resource->save();
                    }
                    $salePolicies = NationResourceSalePolicy::query()
                        ->where('nation_id', $lockedNation->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    foreach ($salePolicies as $salePolicy) {
                        $salePolicy->delete();
                    }

                    $capital->delete();
                    $membership->delete();
                    $lockedNation->money = 0;
                    $lockedNation->idle_counter = 0;
                    $lockedNation->state = 'abandoned';
                    $lockedNation->save();

                    $occurredAt = now();
                    DB::table('audit_events')->insert([
                        'actor_user_id' => $user->id,
                        'world_id' => $lockedWorld->id,
                        'turn' => $lockedWorld->current_turn,
                        'nation_id' => $lockedNation->id,
                        'x' => $oldCapital['x'],
                        'y' => $oldCapital['y'],
                        'message' => "{$lockedNation->name}は破棄され、忘れ去られた。",
                        'visibility' => 'public',
                        'event_type' => 'nation.abandoned',
                        'severity' => 'critical',
                        'subject_type' => Nation::class,
                        'subject_id' => $lockedNation->id,
                        'metadata' => json_encode([
                            'nation_id' => $lockedNation->id,
                            'nation_number' => $lockedNation->nation_number,
                            'nation_name' => $lockedNation->name,
                            'actor_user_id' => $user->id,
                            'world_id' => $lockedWorld->id,
                            'target_turn' => $lockedWorld->current_turn,
                            'current_turn' => $lockedWorld->current_turn,
                            'old_capital_map_cell_id' => $oldCapital['map_cell_id'],
                            'old_capital_x' => $oldCapital['x'],
                            'old_capital_y' => $oldCapital['y'],
                            'affected_owned_cell_count' => $ownedCellCount,
                            'affected_neutral_cleanup_cell_count' => $neutralCleanupCellCount,
                            'removed_monster_count' => $monsterRemovedCount,
                            'changed_chunk_count' => count($chunkIds),
                        ], JSON_THROW_ON_ERROR),
                        'occurred_at' => $occurredAt,
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ]);

                    return [
                        'nation_id' => $lockedNation->id,
                        'state' => $lockedNation->state,
                        'owned_cell_count' => $ownedCellCount,
                        'neutral_cleanup_cell_count' => $neutralCleanupCellCount,
                        'monster_removed_count' => $monsterRemovedCount,
                        'changed_chunk_count' => count($chunkIds),
                    ];
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
            throw new NationAbandonmentConflictException(
                'nation_not_active',
                'この島は現在の島として破棄できません。',
            );
        }
        if (! NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->exists()) {
            throw new AuthorizationException('自分の島だけを破棄できます。');
        }
    }
}
