<?php

namespace App\Application;

use App\Domain\Command\CapitalCorePolicy;
use App\Domain\Command\CommandParametersValidator;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Command\MissileTargetPolicy;
use App\Domain\Command\OwnerFacilityOverbuildPolicy;
use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Command\SettlementOverbuildPolicy;
use App\Domain\Command\TerritoryExpansionFacts;
use App\Domain\Command\TerritoryExpansionPolicy;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Map\GridCoordinate;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CommandQueueService
{
    /** @var list<string> */
    private const DANGEROUS_OWNER_OVERBUILD_EFFECTS = ['defense_self_destruct', 'monument_flight'];

    public function __construct(
        private readonly CommandParametersValidator $parameters,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly LegacyCommandQueueOrder $legacyOrder,
        private readonly CommandQuantitySemantics $quantitySemantics,
        private readonly NationCommandTargetService $nationTargets,
        private readonly TerritoryExpansionPolicy $territoryExpansion,
        private readonly CapitalCorePolicy $capitalCores,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{queue: NationCommandQueue, item: NationCommandQueueItem}
     */
    public function add(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        string $commandKey,
        ?int $targetX,
        ?int $targetY,
        string $requestKey,
        int $expectedVersion,
        int $quantity = DevelopmentPlanQuantity::DEFAULT,
        array $parameters = [],
        ?int $position = null,
        bool $quantityProvided = false,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $commandKey, $targetX, $targetY, $requestKey, $expectedVersion, $quantity, $parameters, $position, $quantityProvided): array {
            $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation, $membership] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $this->assertMapSpace($lockedNation, $mapSpace);
            $definition = CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)
                ->where('key', $commandKey)
                ->where('enabled', true)
                ->first();
            if ($definition === null) {
                throw new PlayerFacingCommandException('利用できないcommandです。');
            }

            [$targetX, $targetY] = $this->resolveTargetCoordinates(
                $lockedNation,
                $mapSpace,
                $definition,
                $targetX,
                $targetY,
            );

            $queue = NationCommandQueue::query()->firstOrCreate(
                ['nation_id' => $lockedNation->id],
                ['map_space_id' => $mapSpace->id, 'version' => 1],
            );
            $queue = NationCommandQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->map_space_id !== $mapSpace->id) {
                throw new DomainException('queueとmap spaceが一致しません。');
            }

            $duplicate = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                return ['queue' => $queue, 'item' => $duplicate->load('definition')];
            }
            $this->assertVersion($queue, $expectedVersion);
            $this->repairLegacyStagedItems($user, $queue);

            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->orderBy('id')
                ->with('definition')
                ->lockForUpdate()
                ->get();
            $limit = $this->queueLimit($world);
            if ($activeItems->count() >= $limit) {
                throw new PlayerFacingCommandException("command queueの上限{$limit}件に達しています。");
            }
            if ($activeItems->contains(static fn (NationCommandQueueItem $item): bool => $item->queue_position === null || $item->queue_position < 1 || $item->queue_position > $limit
            )) {
                $this->compact($queue);
                $activeItems = NationCommandQueueItem::query()
                    ->where('nation_command_queue_id', $queue->id)
                    ->where('status', 'queued')
                    ->orderBy('queue_position')
                    ->orderBy('id')
                    ->with('definition')
                    ->lockForUpdate()
                    ->get();
            }
            $position ??= $this->firstAutomaticPosition($activeItems->pluck('queue_position')->all(), $limit);
            if ($position < 1 || $position > $limit) {
                throw new PlayerFacingCommandException("挿入位置は1から{$limit}の範囲で指定してください。");
            }

            // A queue item is a future plan. Registration proves only that the
            // coordinate and parameters are structurally valid; the locked
            // target state and assets are revalidated immediately before execution.
            $target = $this->targetCell($mapSpace, $targetX, $targetY);
            if (SettlementOverbuildPolicy::protectsCapital($definition->key, $target->facility?->key)) {
                throw new PlayerFacingCommandException('首都を通常建設commandで上書きすることはできません。');
            }
            $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
            $this->quantitySemantics->validateForRegistration($definition, $quantity, $quantityProvided);
            $schemas = $definition->metadata['parameters'] ?? [];
            if (! is_array($schemas)) {
                throw new DomainException('command parameter schemaが不正です。');
            }
            $parameters = $this->parameters->validate($schemas, $parameters);
            $this->nationTargets->validateRegistration($lockedNation, $definition, $parameters);
            $queue->setRelation('items', $activeItems);
            $projectedTarget = $this->projectCellStateBeforePosition(
                $target,
                $queue,
                $position,
                $lockedNation,
                $mapSpace,
            );
            $ownerOverbuildEffect = OwnerFacilityOverbuildPolicy::effectForState(
                $definition,
                $lockedNation,
                $projectedTarget,
            );
            if ($ownerOverbuildEffect === 'monument_flight') {
                $targetNationId = $parameters['target_nation_id'] ?? null;
                if (! is_int($targetNationId)) {
                    throw new PlayerFacingCommandException('この位置への記念碑建設には対象島を選択してください。');
                }
                $this->nationTargets->validateMonumentFlightRegistration($lockedNation, $targetNationId);
            }
            $byPosition = $activeItems->keyBy(
                static fn (NationCommandQueueItem $item): int => (int) $item->queue_position,
            );
            /** @var Collection<int, NationCommandQueueItem> $shifted */
            $shifted = new Collection;
            for ($shiftPosition = $position; $byPosition->has($shiftPosition); $shiftPosition++) {
                if ($shiftPosition >= $limit) {
                    throw new PlayerFacingCommandException('選択した位置へ挿入すると開発計画の末尾を超えます。');
                }

                $shifted->push($byPosition->get($shiftPosition));
            }
            $shiftedIds = array_fill_keys($shifted->modelKeys(), true);
            $proposedItems = $activeItems->map(static function (NationCommandQueueItem $item) use ($shiftedIds): NationCommandQueueItem {
                $proposed = clone $item;
                if (isset($shiftedIds[$item->id])) {
                    $proposed->queue_position = (int) $item->queue_position + 1;
                }

                return $proposed;
            });
            $proposedItem = new NationCommandQueueItem([
                'queue_position' => $position,
                'target_x' => $targetX,
                'target_y' => $targetY,
                'quantity' => $quantity,
                'parameters' => $parameters,
                'status' => 'queued',
            ]);
            $proposedItem->setRelation('definition', $definition);
            $proposedItems->push($proposedItem);
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $proposedItems,
                $lockedNation,
                $mapSpace,
            );
            if ($shifted->isNotEmpty()) {
                NationCommandQueueItem::query()->whereIn('id', $shifted->modelKeys())
                    ->update(['queue_position' => null]);
                foreach ($shifted->sortByDesc('queue_position') as $shiftedItem) {
                    NationCommandQueueItem::query()->whereKey($shiftedItem->id)
                        ->update(['queue_position' => (int) $shiftedItem->queue_position + 1]);
                }
            }

            if ($definition->ruleset_version_id !== $world->ruleset_version_id) {
                throw new DomainException('Command definition no longer matches the locked World ruleset.');
            }

            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => $position,
                'target_x' => $targetX,
                'target_y' => $targetY,
                'quantity' => $quantity,
                'parameters' => $parameters === [] ? (object) [] : $parameters,
                'status' => 'queued',
                'queued_by_membership_id' => $membership->id,
                'request_key' => $requestKey,
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.queued', $item, [
                'command_key' => $commandKey,
                'x' => $targetX,
                'y' => $targetY,
                'quantity' => $quantity,
            ]);

            return ['queue' => $queue, 'item' => $item->load('definition')];
        }, 3);
    }

    /**
     * @param  array<int, array{id: int, position: int}>  $placements
     */
    public function reposition(User $user, Nation $nation, array $placements, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $placements, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $this->repairLegacyStagedItems($user, $queue);
            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->orderBy('id')
                ->with('definition')
                ->lockForUpdate()
                ->get();
            $currentIds = $activeItems->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $receivedIds = collect($placements)->pluck('id')->map(static fn (mixed $id): int => (int) $id)
                ->sort()->values()->all();
            $positions = collect($placements)->pluck('position')->all();
            $limit = $this->queueLimit($world);
            if ($currentIds !== $receivedIds || count($positions) !== count(array_unique($positions))) {
                throw new PlayerFacingCommandException('並べ替え対象が現在の開発計画と一致しません。');
            }
            if (collect($positions)->contains(static fn (mixed $position): bool => $position < 1 || $position > $limit)) {
                throw new PlayerFacingCommandException("開発計画の位置は1から{$limit}の範囲で指定してください。");
            }
            $placementsById = collect($placements)->keyBy('id');
            $proposedItems = $activeItems->map(static function (NationCommandQueueItem $item) use ($placementsById): NationCommandQueueItem {
                $proposed = clone $item;
                $proposed->queue_position = (int) $placementsById->get($item->id)['position'];

                return $proposed;
            });
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $proposedItems,
                $lockedNation,
                MapSpace::query()->findOrFail($queue->map_space_id),
            );

            if ($currentIds !== []) {
                NationCommandQueueItem::query()->whereIn('id', $currentIds)
                    ->update(['queue_position' => null]);
                foreach ($placements as $placement) {
                    NationCommandQueueItem::query()->whereKey($placement['id'])
                        ->update(['queue_position' => $placement['position']]);
                }
            }
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.reordered', $queue, ['placements' => $placements]);

            return $queue;
        }, 3);
    }

    public function updateQuantity(
        User $user,
        Nation $nation,
        NationCommandQueueItem $item,
        int $quantity,
        int $expectedVersion,
    ): NationCommandQueue {
        return DB::transaction(function () use ($user, $nation, $item, $quantity, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $this->repairLegacyStagedItems($user, $queue);
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new PlayerFacingCommandException('編集できないcommandです。');
            }
            $item->loadMissing('definition');
            $this->quantitySemantics->assertEditable($item->definition);
            $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
            $oldQuantity = $item->quantity;
            $item->update(['quantity' => $quantity]);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.quantity_updated', $item, [
                'old_quantity' => $oldQuantity,
                'new_quantity' => $quantity,
            ]);

            return $queue;
        }, 3);
    }

    /** @param array<int, int> $orderedIds */
    public function reorder(User $user, Nation $nation, array $orderedIds, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $orderedIds, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $this->repairLegacyStagedItems($user, $queue);
            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->orderBy('id')
                ->with('definition')
                ->lockForUpdate()
                ->get();
            $current = $activeItems->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $expected = $current;
            $received = $orderedIds;
            sort($expected);
            sort($received);
            if ($expected !== $received) {
                throw new PlayerFacingCommandException('reorder対象が現在のqueueと一致しません。');
            }
            $positionsById = collect($orderedIds)->flip();
            $proposedItems = $activeItems->map(static function (NationCommandQueueItem $item) use ($positionsById): NationCommandQueueItem {
                $proposed = clone $item;
                $proposed->queue_position = (int) $positionsById->get($item->id) + 1;

                return $proposed;
            });
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $proposedItems,
                $lockedNation,
                MapSpace::query()->findOrFail($queue->map_space_id),
            );

            NationCommandQueueItem::query()->whereIn('id', $orderedIds)
                ->update(['queue_position' => null]);
            foreach ($orderedIds as $index => $id) {
                NationCommandQueueItem::query()->whereKey($id)->update(['queue_position' => $index + 1]);
            }
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.reordered', $queue, ['ordered_ids' => $orderedIds]);

            return $queue;
        }, 3);
    }

    public function cancel(User $user, Nation $nation, NationCommandQueueItem $item, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $item, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $this->repairLegacyStagedItems($user, $queue);
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new PlayerFacingCommandException('取消できないcommandです。');
            }
            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->orderBy('id')
                ->with('definition')
                ->lockForUpdate()
                ->get();
            $nextPosition = 1;
            $proposedItems = $activeItems
                ->reject(static fn (NationCommandQueueItem $activeItem): bool => $activeItem->id === $item->id)
                ->map(static function (NationCommandQueueItem $activeItem) use (&$nextPosition): NationCommandQueueItem {
                    $proposed = clone $activeItem;
                    $proposed->queue_position = $nextPosition++;

                    return $proposed;
                })->values();
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $proposedItems,
                $lockedNation,
                MapSpace::query()->findOrFail($queue->map_space_id),
            );
            $item->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
            $this->compact($queue);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.cancelled', $item, []);

            return $queue;
        }, 3);
    }

    /**
     * @return array{queue: NationCommandQueue, inserted_count: int, truncated_count: int, candidate_count: int, duplicate: bool}
     */
    public function bulkInsert(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        string $action,
        int $position,
        string $requestKey,
        int $expectedVersion,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $action, $position, $requestKey, $expectedVersion): array {
            $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation, $membership] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $this->assertMapSpace($lockedNation, $mapSpace);
            $limit = $this->queueLimit($world);
            if ($position < 1 || $position > $limit) {
                throw new PlayerFacingCommandException("挿入位置は1から{$limit}の範囲で指定してください。");
            }
            if (! in_array($action, [
                'clear_all', 'level_all', 'reclaim_clear_all', 'reclaim_level_all',
            ], true)) {
                throw new PlayerFacingCommandException('利用できない一括操作です。');
            }

            $queue = NationCommandQueue::query()->firstOrCreate(
                ['nation_id' => $lockedNation->id],
                ['map_space_id' => $mapSpace->id, 'version' => 1],
            );
            $queue = NationCommandQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->map_space_id !== $mapSpace->id) {
                throw new DomainException('queueとmap spaceが一致しません。');
            }

            $firstDerivedRequestKey = $this->derivedBulkRequestKey($requestKey, 0);
            $completedRequest = DB::table('nation_command_queue_bulk_requests')
                ->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $requestKey)
                ->first(['candidate_count', 'inserted_count', 'truncated_count']);
            if ($completedRequest !== null) {
                return [
                    'queue' => $queue,
                    'inserted_count' => (int) $completedRequest->inserted_count,
                    'truncated_count' => (int) $completedRequest->truncated_count,
                    'candidate_count' => (int) $completedRequest->candidate_count,
                    'duplicate' => true,
                ];
            }
            if (NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $firstDerivedRequestKey)->exists()) {
                return [
                    'queue' => $queue,
                    'inserted_count' => 0,
                    'truncated_count' => 0,
                    'candidate_count' => 0,
                    'duplicate' => true,
                ];
            }
            $this->assertVersion($queue, $expectedVersion);
            $legacyDiscarded = $this->repairLegacyStagedItems($user, $queue);

            $commandKeys = match ($action) {
                'clear_all' => ['land_clear'],
                'level_all' => ['land_level'],
                'reclaim_clear_all' => ['reclaim', 'land_clear'],
                'reclaim_level_all' => ['reclaim', 'land_level'],
            };
            $definitions = CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)
                ->whereIn('key', array_values(array_unique($commandKeys)))
                ->where('enabled', true)->get()->keyBy('key');
            foreach (array_unique($commandKeys) as $commandKey) {
                if (! $definitions->has($commandKey)) {
                    throw new DomainException("Bulk command definition {$commandKey} is missing.");
                }
            }

            $terrainKeys = str_starts_with($action, 'reclaim_')
                ? ['shallow']
                : ['wasteland', 'scorched'];
            $cells = MapCell::query()->where('map_space_id', $mapSpace->id)
                ->whereIn('terrain_definition_id', function ($query) use ($terrainKeys): void {
                    $query->select('id')->from('terrain_definitions')->whereIn('key', $terrainKeys);
                })
                ->when(! str_starts_with($action, 'reclaim_'), fn ($query) => $query->where('owner_nation_id', $lockedNation->id))
                ->orderBy('y')->orderBy('x')->lockForUpdate()->with(['terrain', 'facility'])->get();

            $candidates = [];
            foreach ($cells as $cell) {
                if (str_starts_with($action, 'reclaim_')) {
                    try {
                        $this->validateTarget($lockedNation, $mapSpace, $definitions['reclaim'], $cell);
                    } catch (PlayerFacingCommandException) {
                        continue;
                    }
                }
                foreach ($commandKeys as $commandKey) {
                    $candidates[] = [
                        'definition' => $definitions[$commandKey],
                        'x' => (int) $cell->x,
                        'y' => (int) $cell->y,
                    ];
                }
            }
            if ($candidates === []) {
                if ($legacyDiscarded > 0) {
                    $queue->increment('version');
                    $queue->refresh();
                }
                $this->recordBulkRequest($queue, $requestKey, $action, $position, 0, 0, 0);

                return [
                    'queue' => $queue,
                    'inserted_count' => 0,
                    'truncated_count' => 0,
                    'candidate_count' => 0,
                    'duplicate' => false,
                ];
            }

            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)->where('status', 'queued')
                ->orderBy('queue_position')->orderBy('id')->with('definition')->lockForUpdate()->get();
            $prefix = $activeItems->filter(fn (NationCommandQueueItem $item): bool => (int) $item->queue_position < $position)->all();
            $suffix = $activeItems->filter(fn (NationCommandQueueItem $item): bool => (int) $item->queue_position >= $position)->all();
            $generated = array_map(static fn (array $candidate): array => ['generated' => $candidate], $candidates);
            $generatedCapacity = max(0, $limit - count($prefix));
            $generatedToKeep = min(count($generated), $generatedCapacity);
            if (str_starts_with($action, 'reclaim_')) {
                $generatedToKeep -= $generatedToKeep % count($commandKeys);
            }
            $keptGenerated = array_slice($generated, 0, $generatedToKeep);
            $keptSuffixCount = max(0, $limit - count($prefix) - count($keptGenerated));
            $merged = [
                ...array_map(static fn (NationCommandQueueItem $item): array => ['existing' => $item], $prefix),
                ...$keptGenerated,
                ...array_map(
                    static fn (NationCommandQueueItem $item): array => ['existing' => $item],
                    array_slice($suffix, 0, $keptSuffixCount),
                ),
            ];
            $dropped = [
                ...array_slice($generated, $generatedToKeep),
                ...array_map(
                    static fn (NationCommandQueueItem $item): array => ['existing' => $item],
                    array_slice($suffix, $keptSuffixCount),
                ),
            ];
            $kept = array_slice($merged, 0, $limit);
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $this->proposedBulkItems($kept),
                $lockedNation,
                $mapSpace,
            );
            if ($activeItems->isNotEmpty()) {
                NationCommandQueueItem::query()->whereIn('id', $activeItems->modelKeys())->update(['queue_position' => null]);
            }

            $insertedCount = 0;
            foreach ($kept as $index => $entry) {
                $queuePosition = $index + 1;
                if (isset($entry['existing'])) {
                    NationCommandQueueItem::query()->whereKey($entry['existing']->id)
                        ->update(['queue_position' => $queuePosition]);

                    continue;
                }
                $candidate = $entry['generated'];
                NationCommandQueueItem::query()->create([
                    'nation_command_queue_id' => $queue->id,
                    'command_definition_id' => $candidate['definition']->id,
                    'queue_position' => $queuePosition,
                    'target_x' => $candidate['x'],
                    'target_y' => $candidate['y'],
                    'quantity' => DevelopmentPlanQuantity::DEFAULT,
                    'parameters' => (object) [],
                    'status' => 'queued',
                    'queued_by_membership_id' => $membership->id,
                    'request_key' => $this->derivedBulkRequestKey($requestKey, $insertedCount),
                    'queued_at' => now(),
                    'failure_metadata' => [],
                ]);
                $insertedCount++;
            }
            foreach ($dropped as $entry) {
                if (isset($entry['existing'])) {
                    $entry['existing']->update([
                        'status' => 'cancelled',
                        'queue_position' => null,
                        'cancelled_at' => now(),
                    ]);
                    $this->audit($user, 'command.cancelled', $entry['existing'], ['reason' => 'bulk_tail_truncated']);
                }
            }
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.bulk_inserted', $queue, [
                'action' => $action,
                'position' => $position,
                'candidate_count' => count($candidates),
                'inserted_count' => $insertedCount,
                'truncated_count' => count($dropped),
            ]);
            $this->recordBulkRequest(
                $queue,
                $requestKey,
                $action,
                $position,
                count($candidates),
                $insertedCount,
                count($dropped),
            );

            return [
                'queue' => $queue,
                'inserted_count' => $insertedCount,
                'truncated_count' => count($dropped),
                'candidate_count' => count($candidates),
                'duplicate' => false,
            ];
        }, 3);
    }

    /** @return array{queue: NationCommandQueue, deleted_count: int} */
    public function cancelFromPosition(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        int $position,
        int $expectedVersion,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $position, $expectedVersion): array {
            $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $legacyDiscarded = $this->repairLegacyStagedItems($user, $queue);
            $items = NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')->where('queue_position', '>=', $position)
                ->orderBy('queue_position')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
                $this->audit($user, 'command.cancelled', $item, ['reason' => 'cancel_from_position']);
            }
            if ($items->isNotEmpty() || $legacyDiscarded > 0) {
                $this->compact($queue);
                $queue->increment('version');
                $queue->refresh();
            }

            return ['queue' => $queue, 'deleted_count' => $items->count()];
        }, 3);
    }

    private function derivedBulkRequestKey(string $requestKey, int $index): string
    {
        $hex = substr(hash('sha256', $requestKey.':'.$index), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return implode('-', [
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12),
        ]);
    }

    private function recordBulkRequest(
        NationCommandQueue $queue,
        string $requestKey,
        string $action,
        int $position,
        int $candidateCount,
        int $insertedCount,
        int $truncatedCount,
    ): void {
        DB::table('nation_command_queue_bulk_requests')->insert([
            'nation_command_queue_id' => $queue->id,
            'request_key' => $requestKey,
            'action' => $action,
            'position' => $position,
            'candidate_count' => $candidateCount,
            'inserted_count' => $insertedCount,
            'truncated_count' => $truncatedCount,
            'created_at' => now(),
        ]);
    }

    public function queueFor(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        bool $mutationPreflight = false,
    ): NationCommandQueue {
        $this->membership($user, $nation);
        $this->assertMapSpace($nation, $mapSpace);
        $world = World::query()->whereKey($nation->world_id)->with('rulesetVersion')->firstOrFail();
        if ($mutationPreflight) {
            $this->rulesetGuard->assertMutable($world, $world->rulesetVersion);
        }
        $this->assertUniversalQuantityRuleset($world);

        $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->first();
        if ($queue === null) {
            $queue = new NationCommandQueue([
                'nation_id' => $nation->id,
                'map_space_id' => $mapSpace->id,
                'version' => 1,
            ]);
            $queue->setRelation('items', new Collection);

            return $queue;
        }

        $queue->load([
            'items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'),
            'items.definition',
        ]);
        $queue->setRelation('items', $this->legacyOrder->project($queue->items));

        return $queue;
    }

    /**
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}
     */
    public function projectCellStateBeforePosition(
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
        MapSpace $mapSpace,
    ): array {
        $state = [
            'terrain_key' => $cell->terrain->key,
            'facility_key' => $cell->facility?->key,
            'owner_nation_id' => $cell->owner_nation_id,
        ];

        foreach ($queue->items as $item) {
            if ($item->queue_position >= $beforePosition
                || $item->target_x !== $cell->x
                || $item->target_y !== $cell->y) {
                continue;
            }
            $definition = $item->definition;
            $matches = $definition->key === 'territory_expand'
                ? $this->projectedTerritoryTargetMatches(
                    $definition,
                    $state,
                    $cell,
                    $queue,
                    (int) $item->queue_position,
                    $nation,
                    $mapSpace,
                )
                : $this->projectedTargetMatches($definition, $state, $nation);
            if (! $matches) {
                continue;
            }
            $state = $this->applyProjectedResult($definition, $state, $nation);
        }

        return $state;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     */
    public function projectedTargetMatches(CommandDefinition $definition, array $state, Nation $nation): bool
    {
        if (! in_array($state['terrain_key'], $definition->target_terrain_keys, true)) {
            return false;
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $state['facility_key'])) {
            return false;
        }
        if ($definition->requires_empty_facility && $state['facility_key'] !== null
            && ! SettlementOverbuildPolicy::allows($definition->key, $state['facility_key'])
            && $this->projectedOwnerOverbuildEffect($definition, $nation, $state) === null) {
            return false;
        }
        if ($definition->target_facility_keys !== []
            && ! in_array($state['facility_key'], $definition->target_facility_keys, true)) {
            return false;
        }
        if ($definition->key === 'territory_expand') {
            return $state['owner_nation_id'] === null;
        }
        if ($definition->key === 'excavate'
            && in_array($state['terrain_key'], ['sea', 'shallow'], true)
            && $state['facility_key'] !== null) {
            return false;
        }
        if (in_array($definition->key, ['reclaim', 'build_seabed_base', 'excavate'], true)) {
            return $state['owner_nation_id'] === null || $state['owner_nation_id'] === $nation->id;
        }

        return $state['owner_nation_id'] === $nation->id;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     */
    public function projectedOwnerOverbuildEffect(
        CommandDefinition $definition,
        Nation $nation,
        array $state,
    ): ?string {
        return OwnerFacilityOverbuildPolicy::effectForState($definition, $nation, $state);
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     */
    public function projectedTerritoryTargetMatches(
        CommandDefinition $definition,
        array $state,
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
        MapSpace $mapSpace,
    ): bool {
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->neighborsWithin(
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        );
        $neighbors = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('x', $coordinate->x)
                        ->where('y', $coordinate->y));
                }
            })
            ->with(['terrain', 'facility'])
            ->get();
        $adjacentActorTerritory = $neighbors->contains(function (MapCell $neighbor) use ($queue, $beforePosition, $nation, $mapSpace): bool {
            $projected = $this->projectCellStateBeforePosition(
                $neighbor,
                $queue,
                $beforePosition,
                $nation,
                $mapSpace,
            );

            return $projected['owner_nation_id'] === $nation->id;
        });

        try {
            $this->validateTerritoryExpansionState(
                $nation,
                $mapSpace,
                $definition,
                $cell,
                $state,
                $adjacentActorTerritory,
            );

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}
     */
    private function applyProjectedResult(CommandDefinition $definition, array $state, Nation $nation): array
    {
        $ownerOverbuildEffect = $this->projectedOwnerOverbuildEffect($definition, $nation, $state);
        if ($ownerOverbuildEffect === 'defense_self_destruct') {
            $state['terrain_key'] = 'sea';
            $state['facility_key'] = null;

            return $state;
        }
        if ($ownerOverbuildEffect === 'monument_flight') {
            $state['terrain_key'] = 'wasteland';
            $state['facility_key'] = null;

            return $state;
        }

        if ($definition->key === 'reclaim') {
            $state['terrain_key'] = $state['terrain_key'] === 'sea' ? 'shallow' : 'wasteland';
            $state['owner_nation_id'] = $nation->id;
        } elseif ($definition->key === 'excavate') {
            $state['terrain_key'] = match ($state['terrain_key']) {
                'sea' => 'sea',
                'shallow' => 'sea',
                'mountain' => 'wasteland',
                default => 'shallow',
            };
            $state['facility_key'] = null;
        } else {
            if ($definition->result_terrain_key !== null) {
                $state['terrain_key'] = $definition->result_terrain_key;
            }
            if ($definition->result_facility_key !== null) {
                $state['facility_key'] = $definition->result_facility_key;
            }
        }

        if (in_array($definition->key, ['land_clear', 'land_level', 'logging', 'plant_forest'], true)) {
            $state['facility_key'] = null;
        }
        if ($definition->key === 'territory_expand') {
            $state['owner_nation_id'] = $nation->id;
        }

        return $state;
    }

    /**
     * @param  list<array{existing: NationCommandQueueItem}|array{generated: array{definition: CommandDefinition, x: int, y: int}}>  $entries
     * @return Collection<int, NationCommandQueueItem>
     */
    private function proposedBulkItems(array $entries): Collection
    {
        $items = new Collection;
        foreach ($entries as $index => $entry) {
            if (isset($entry['existing'])) {
                $item = clone $entry['existing'];
            } else {
                $candidate = $entry['generated'];
                $item = new NationCommandQueueItem([
                    'target_x' => $candidate['x'],
                    'target_y' => $candidate['y'],
                    'status' => 'queued',
                ]);
                $item->setRelation('definition', $candidate['definition']);
            }
            $item->queue_position = $index + 1;
            $items->push($item);
        }

        return $items;
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $currentItems
     * @param  Collection<int, NationCommandQueueItem>  $proposedItems
     */
    private function assertNoNewDangerousOverbuildEffects(
        NationCommandQueue $queue,
        Collection $currentItems,
        Collection $proposedItems,
        Nation $nation,
        MapSpace $mapSpace,
    ): void {
        try {
            $before = $this->projectedOwnerOverbuildEffectsByItem(
                $queue,
                $currentItems,
                $nation,
                $mapSpace,
            );
            $after = $this->projectedOwnerOverbuildEffectsByItem(
                $queue,
                $proposedItems,
                $nation,
                $mapSpace,
            );
            foreach ($after as $itemId => $effect) {
                if (in_array($effect, self::DANGEROUS_OWNER_OVERBUILD_EFFECTS, true)
                    && ($before[$itemId] ?? null) !== $effect) {
                    throw new PlayerFacingCommandException(
                        'この操作により既存commandが未確認の危険な上書き効果へ変わるため実行できません。対象commandを削除し、希望位置へ追加し直してください。',
                    );
                }
            }
        } finally {
            $queue->setRelation('items', $currentItems);
        }
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $items
     * @return array<int, string|null>
     */
    private function projectedOwnerOverbuildEffectsByItem(
        NationCommandQueue $queue,
        Collection $items,
        Nation $nation,
        MapSpace $mapSpace,
    ): array {
        $queue->setRelation(
            'items',
            $items->sortBy(static fn (NationCommandQueueItem $item): int => (int) $item->queue_position)->values(),
        );
        $effects = [];
        foreach ($queue->items as $item) {
            if (! $item->exists) {
                continue;
            }
            $item->loadMissing('definition');
            $declaredEffect = $item->definition->metadata['owner_overbuild_effect'] ?? null;
            if (! in_array($declaredEffect, self::DANGEROUS_OWNER_OVERBUILD_EFFECTS, true)) {
                continue;
            }
            $cell = $this->targetCell($mapSpace, $item->target_x, $item->target_y);
            $state = $this->projectCellStateBeforePosition(
                $cell,
                $queue,
                (int) $item->queue_position,
                $nation,
                $mapSpace,
            );
            $effects[$item->id] = $this->projectedOwnerOverbuildEffect($item->definition, $nation, $state);
        }

        return $effects;
    }

    public function validateTarget(Nation $nation, MapSpace $mapSpace, CommandDefinition $definition, MapCell $cell): void
    {
        $terrainKey = $cell->terrain->key;
        $facilityKey = $cell->facility?->key;
        $ownerOverbuildEffect = OwnerFacilityOverbuildPolicy::effect($definition, $nation, $cell);
        if ($definition->key === 'territory_expand') {
            $this->validateTerritoryExpansionState(
                $nation,
                $mapSpace,
                $definition,
                $cell,
                [
                    'terrain_key' => $terrainKey,
                    'facility_key' => $facilityKey,
                    'owner_nation_id' => $cell->owner_nation_id,
                ],
                $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 1, false),
            );

            return;
        }
        if (! in_array($terrainKey, $definition->target_terrain_keys, true)) {
            throw new PlayerFacingCommandException('対象地形ではこのcommandをqueueへ追加できません。');
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $facilityKey)) {
            throw new PlayerFacingCommandException('首都を通常建設commandで上書きすることはできません。');
        }
        if ($definition->requires_empty_facility && $facilityKey !== null
            && ! SettlementOverbuildPolicy::allows($definition->key, $facilityKey)
            && $ownerOverbuildEffect === null) {
            throw new PlayerFacingCommandException('施設のあるcellにはこのcommandをqueueへ追加できません。');
        }
        if ($definition->target_facility_keys !== [] && ! in_array($facilityKey, $definition->target_facility_keys, true)) {
            throw new PlayerFacingCommandException('対象施設ではこのcommandをqueueへ追加できません。');
        }

        if (in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            $world = $nation->world()->with('rulesetVersion')->firstOrFail();
            $targetPolicy = MissileTargetPolicy::explicitTargetState($world->rulesetVersion->settings);
            if ($targetPolicy === MissileTargetPolicy::ANY_EXISTING_COORDINATE) {
                return;
            }
            $targetNation = $cell->owner_nation_id === null
                ? null
                : Nation::query()->whereKey($cell->owner_nation_id)->first();
            if ($targetNation === null || $targetNation->world_id !== $world->id || $targetNation->state !== 'active') {
                throw new PlayerFacingCommandException('active Nation所有のcellだけを対象にできます。');
            }

            return;
        }

        if (in_array($definition->key, ['reclaim'], true)) {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                throw new PlayerFacingCommandException('他国所有の水域は埋め立てできません。');
            }
            if (! $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 1, false)) {
                throw new PlayerFacingCommandException('埋め立て対象の隣に自国領がありません。');
            }

            return;
        }
        if ($definition->key === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true)) {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                throw new PlayerFacingCommandException('他国所有の水域は掘削できません。');
            }
            if ($terrainKey === 'sea' && $cell->facility_definition_id !== null) {
                throw new PlayerFacingCommandException('施設のある海では油田探索できません。');
            }
            if (! $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 3)) {
                throw new PlayerFacingCommandException('掘削対象の3hex以内に自国領がありません。');
            }

            return;
        }
        if ($cell->owner_nation_id !== $nation->id) {
            throw new PlayerFacingCommandException('自国領のcellだけを対象にできます。');
        }
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     */
    public function validateTerritoryExpansionState(
        Nation $nation,
        MapSpace $mapSpace,
        CommandDefinition $definition,
        MapCell $cell,
        array $state,
        bool $adjacentActorTerritory,
    ): void {
        $world = World::query()->whereKey($nation->world_id)->with('rulesetVersion')->firstOrFail();
        if ($cell->map_space_id !== $mapSpace->id || $mapSpace->world_id !== $world->id) {
            throw new DomainException('領土拡張のtargetはactorと同じWorldのsurface cellである必要があります。');
        }
        $targetOwner = $state['owner_nation_id'] === null
            ? null
            : Nation::query()->whereKey($state['owner_nation_id'])->first();
        $monsterOccupied = MonsterOccupancy::query()->where('map_cell_id', $cell->id)->exists();
        $facts = new TerritoryExpansionFacts(
            actorNationId: $nation->id,
            actorNationState: $nation->state,
            targetOwnerNationId: $state['owner_nation_id'],
            targetOwnerNationState: $targetOwner?->world_id === $world->id ? $targetOwner->state : null,
            targetOwnerInActorWorld: $state['owner_nation_id'] === null || $targetOwner?->world_id === $world->id,
            terrainKey: $state['terrain_key'],
            facilityKey: $state['facility_key'],
            monsterOccupied: $monsterOccupied,
            capitalCoreProtected: $this->capitalCoreProtected(
                $world,
                new GridCoordinate($cell->x, $cell->y),
                $nation->id,
            ),
            adjacentActorTerritory: $adjacentActorTerritory,
            definitionTargetTerrainKeys: $definition->target_terrain_keys,
            definitionRequiresEmptyFacility: $definition->requires_empty_facility,
        );
        $reason = $this->territoryExpansion->failureReason($definition->metadata, $facts);
        if ($reason !== null) {
            throw new PlayerFacingCommandException($this->territoryExpansion->message($reason));
        }
    }

    private function capitalCoreProtected(World $world, GridCoordinate $target, int $newOwnerNationId): bool
    {
        $transfer = $world->rulesetVersion->settings['territory_transfer']['capital_core'] ?? null;
        if (! is_array($transfer) || ($transfer['ownership_transfer_protected'] ?? false) !== true) {
            return false;
        }
        $ownerStates = is_array($transfer['owner_states'] ?? null) ? $transfer['owner_states'] : [];
        $capitals = NationCapital::query()
            ->whereHas('nation', fn ($query) => $query
                ->where('world_id', $world->id)
                ->whereIn('state', $ownerStates))
            ->orderBy('nation_id')
            ->get(['nation_id', 'x', 'y'])
            ->map(static fn (NationCapital $capital): array => [
                'nation_id' => (int) $capital->nation_id,
                'x' => (int) $capital->x,
                'y' => (int) $capital->y,
            ])->all();

        return $this->capitalCores->protectsTransfer(
            $target,
            $newOwnerNationId,
            $capitals,
            (int) ($transfer['radius'] ?? 0),
        );
    }

    private function membership(User $user, Nation $nation): NationMembership
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $nation->id)
            ->where('world_id', $nation->world_id)
            ->where('role', 'owner')
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('自国のcommand queueだけを操作できます。');
        }

        return $membership;
    }

    /** @return array{0: Nation, 1: NationMembership} */
    private function lockActiveOwnerAfterWorld(User $user, Nation $nation, World $world): array
    {
        $lockedNation = Nation::query()
            ->whereKey($nation->id)
            ->where('world_id', $world->id)
            ->lockForUpdate()
            ->first();
        if ($lockedNation === null || $lockedNation->state !== 'active') {
            throw new AuthorizationException('現役ではない島のcommand queueは操作できません。');
        }

        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $lockedNation->id)
            ->where('world_id', $world->id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('自国のcommand queueだけを操作できます。');
        }

        return [$lockedNation, $membership];
    }

    private function assertMapSpace(Nation $nation, MapSpace $mapSpace): void
    {
        if ($mapSpace->world_id !== $nation->world_id) {
            throw new DomainException('Nationとmap spaceのworldが一致しません。');
        }
    }

    private function lockWorldForQueue(Nation $nation): World
    {
        $world = World::query()->whereKey($nation->world_id)->lockForUpdate()->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $this->rulesetGuard->assertMutable($world, $ruleset);
        $world->setRelation('rulesetVersion', $ruleset);
        $this->assertUniversalQuantityRuleset($world);

        return $world;
    }

    private function queueLimit(World $world): int
    {
        $settings = $this->rulesetSettings($world);

        return CommandQueueLimit::fromRulesetSettings($settings);
    }

    private function assertUniversalQuantityRuleset(World $world): void
    {
        $settings = $this->rulesetSettings($world);
        if (! DevelopmentPlanQuantity::matchesContract($settings['development_plan_quantity'] ?? null)) {
            throw new DomainException('Worldのrulesetはuniversal quantity契約へ移行されていません。');
        }
    }

    /** @return array<string, mixed> */
    private function rulesetSettings(World $world): array
    {
        $ruleset = $world->relationLoaded('rulesetVersion')
            ? $world->getRelation('rulesetVersion')
            : $world->rulesetVersion()->firstOrFail();
        if (! $ruleset instanceof RulesetVersion) {
            throw new DomainException('World ruleset relation is invalid.');
        }

        return $ruleset->settings;
    }

    private function targetCell(MapSpace $mapSpace, int $x, int $y): MapCell
    {
        if ($x < $mapSpace->min_x || $x > $mapSpace->max_x || $y < $mapSpace->min_y || $y > $mapSpace->max_y) {
            throw new PlayerFacingCommandException('target x/yがmap bounds外です。');
        }

        $cell = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('x', $x)
            ->where('y', $y)
            ->with(['terrain', 'facility'])
            ->first();
        if ($cell === null) {
            throw new PlayerFacingCommandException('target cellが存在しません。');
        }

        return $cell;
    }

    /** @return array{0: int, 1: int} */
    private function resolveTargetCoordinates(
        Nation $nation,
        MapSpace $mapSpace,
        CommandDefinition $definition,
        ?int $targetX,
        ?int $targetY,
    ): array {
        if ($definition->target_type === 'nation') {
            $capital = $nation->capital()->firstOrFail();
            $cell = $capital->cell()->firstOrFail();
            if ($cell->map_space_id !== $mapSpace->id) {
                throw new DomainException('Nationの首都とmap spaceが一致しません。');
            }

            return [(int) $capital->x, (int) $capital->y];
        }
        if ($targetX === null || $targetY === null) {
            throw new PlayerFacingCommandException('cell対象commandにはtarget x/yが必要です。');
        }

        return [$targetX, $targetY];
    }

    private function hasOwnedCellWithin(Nation $nation, MapSpace $mapSpace, MapCell $cell, int $radius, bool $includeCenter = true): bool
    {
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->radius($radius);
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $cell->x || $coordinate->y !== $cell->y,
            ));
        }

        return MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->exists();
    }

    private function assertVersion(NationCommandQueue $queue, int $expectedVersion): void
    {
        if ($queue->version !== $expectedVersion) {
            throw new OptimisticLockException('command queueが他の操作で更新されました。再読込してください。');
        }
    }

    private function compact(NationCommandQueue $queue): void
    {
        $items = NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        NationCommandQueueItem::query()->whereIn('id', $items->modelKeys())
            ->update(['queue_position' => null]);
        foreach ($items as $index => $item) {
            NationCommandQueueItem::query()->whereKey($item->id)
                ->update(['queue_position' => $index + 1]);
        }
    }

    private function repairLegacyStagedItems(User $user, NationCommandQueue $queue): int
    {
        $items = NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $discarded = $this->legacyOrder->discard($items);
        if ($discarded->isEmpty()) {
            return 0;
        }

        foreach ($discarded as $item) {
            $this->audit($user, 'command.cancelled', $item, [
                'reason' => LegacyCommandQueueOrder::DISCARD_REASON,
                'original_queue_position' => (int) $item->getAttribute('legacy_original_queue_position'),
            ]);
        }
        $this->compact($queue);

        return $discarded->count();
    }

    /** @param array<int, int|null> $occupied */
    private function firstAutomaticPosition(array $occupied, int $limit): int
    {
        $positions = array_flip(array_map('intval', $occupied));
        for ($position = 1; $position <= $limit; $position++) {
            if (! isset($positions[$position])) {
                return $position;
            }
        }

        return $limit + 1;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $user, string $eventType, Model $subject, array $metadata): void
    {
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'event_type' => $eventType,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
