<?php

namespace App\Application;

use App\Application\Underground\UndergroundFacilityService;
use App\Domain\Command\BulkPlanPreviewChangedException;
use App\Domain\Command\CapitalCorePolicy;
use App\Domain\Command\CapitalDestructionPolicy;
use App\Domain\Command\CommandParametersValidator;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\CommandRequestConflictException;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Command\LandDevelopmentDistancePolicy;
use App\Domain\Command\MissileTargetPolicy;
use App\Domain\Command\OwnerFacilityOverbuildPolicy;
use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Command\PortAdjacencyResolver;
use App\Domain\Command\SettlementOverbuildPolicy;
use App\Domain\Command\SurfaceCommandProjectionMemo;
use App\Domain\Command\SurfaceLandProjection;
use App\Domain\Command\TerritoryExpansionFacts;
use App\Domain\Command\TerritoryExpansionPolicy;
use App\Domain\Command\UnderseaConstructionPolicy;
use App\Domain\Command\WindPowerRepairPolicy;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Facility\FacilityRankPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\OwnedLandProximity;
use App\Domain\Monster\MonsterDispatchOptionResolver;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Domain\Underground\Facility\UndergroundCommandDefinition;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use App\Services\MapCellPresenter;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommandQueueService
{
    /** @var list<string> */
    private const DANGEROUS_OWNER_OVERBUILD_EFFECTS = ['defense_self_destruct', 'monument_flight'];

    /** @var array<string, string> */
    private const FACILITY_BUILD_RESULTS = [
        'build_farm' => 'farm',
        'build_factory' => 'factory',
        'build_mine' => 'mine',
        'build_fast_farm' => 'farm',
        'build_fast_factory' => 'factory',
        'build_fast_mine' => 'mine',
        'build_central_bank' => 'central_bank',
        'build_central_granary' => 'central_granary',
        'build_pizzeria' => 'pizzeria',
        'build_thermal_power' => 'thermal_power',
    ];

    public function __construct(
        private readonly CommandParametersValidator $parameters,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly CommandQuantitySemantics $quantitySemantics,
        private readonly NationCommandTargetService $nationTargets,
        private readonly TerritoryExpansionPolicy $territoryExpansion,
        private readonly CapitalCorePolicy $capitalCores,
        private readonly MonsterDispatchOptionResolver $monsterDispatchOptions,
        private readonly UndergroundFacilityService $undergroundFacilities,
        private readonly UndergroundCommandCatalog $undergroundCommands,
        private readonly QueuedCommandDefinitionResolver $queuedCommandDefinitions,
        private readonly CommandQueueCompactor $queueCompactor,
        private readonly FacilityRankPolicy $facilityRanks,
        private readonly CentralFacilityLimit $centralFacilityLimit,
        private readonly DailyQuestService $dailyQuests,
        private readonly UnderseaConstructionPolicy $underseaConstruction,
        private readonly OwnedLandProximity $ownedLand,
        private readonly PortAdjacencyResolver $portAdjacency,
        private readonly SurfaceVisibilityService $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{queue: NationCommandQueue, item: NationCommandQueueItem, duplicate: bool, daily_quest: array<string, int|string|bool>}
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
        ?int $targetLayer = null,
        ?int $targetSlotIndex = null,
        ?int $targetShipId = null,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $commandKey, $targetX, $targetY, $targetLayer, $targetSlotIndex, $targetShipId, $requestKey, $expectedVersion, $quantity, $parameters, $position, $quantityProvided): array {
            $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation, $membership] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $this->assertMapSpace($lockedNation, $mapSpace);
            $ruleset = RulesetVersion::query()->whereKey($world->ruleset_version_id)
                ->firstOrFail(['id', 'key', 'version', 'settings']);
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
                ->with(['definition.rulesetVersion', 'requestRulesetVersion'])
                ->first();
            if ($duplicate !== null) {
                $requestRuleset = $duplicate->requestRulesetVersion;
                if ($requestRuleset === null) {
                    throw new CommandRequestConflictException;
                }
                // Retry identity uses the original request contract, not current gameplay.
                $duplicateDefinition = $this->definitionForItem($duplicate, $requestRuleset);
                if ($commandKey !== $duplicateDefinition->key) {
                    throw new CommandRequestConflictException;
                }
                $requestDefinition = $duplicateDefinition;
                if ($requestDefinition instanceof CommandDefinition
                    && $requestDefinition->ruleset_version_id !== $requestRuleset->id) {
                    $requestDefinition = CommandDefinition::query()
                        ->where('ruleset_version_id', $requestRuleset->id)
                        ->where('key', $duplicateDefinition->key)
                        ->first();
                    if (! $requestDefinition instanceof CommandDefinition) {
                        throw new CommandRequestConflictException;
                    }
                }
                try {
                    $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
                    if ($requestDefinition instanceof UndergroundCommandDefinition) {
                        if ($quantity !== 1 || $parameters !== []) {
                            throw new DomainException('Underground facility commands do not accept quantity or parameters.');
                        }
                    } else {
                        $this->quantitySemantics->validateForRegistration(
                            $requestDefinition,
                            $quantity,
                            $quantityProvided,
                        );
                        $schemas = $requestDefinition->metadata['parameters'] ?? [];
                        if (! is_array($schemas)) {
                            throw new DomainException('Historical command parameter schema is malformed.');
                        }
                        $parameters = $this->parameters->validate($schemas, $parameters);
                    }
                } catch (DomainException) {
                    throw new CommandRequestConflictException;
                }
                if ($requestDefinition instanceof CommandDefinition
                    && $requestDefinition->key === 'scuttle_ship') {
                    $shipId = $duplicate->parameters['ship_id'] ?? null;
                    if (! is_int($shipId) || $targetShipId !== $shipId) {
                        throw new CommandRequestConflictException;
                    }
                    $parameters = ['ship_id' => $shipId];
                } elseif ($targetShipId !== null) {
                    throw new CommandRequestConflictException;
                }
                if ($requestDefinition->target_type === 'underground_slot') {
                    if ($targetX !== null || $targetY !== null || ! is_int($targetLayer) || ! is_int($targetSlotIndex)
                        || $targetLayer !== $duplicate->target_layer || $targetSlotIndex !== $duplicate->target_slot_index) {
                        throw new CommandRequestConflictException;
                    }
                } elseif ($requestDefinition->target_type === 'nation') {
                    $targetX = $duplicate->target_x;
                    $targetY = $duplicate->target_y;
                    $targetLayer = null;
                    $targetSlotIndex = null;
                } elseif (! is_int($targetX) || ! is_int($targetY) || $targetLayer !== null || $targetSlotIndex !== null) {
                    throw new CommandRequestConflictException;
                }
                $requestFingerprint = $this->requestFingerprint(
                    $requestRuleset,
                    $requestDefinition,
                    $targetX,
                    $targetY,
                    $targetLayer,
                    $targetSlotIndex,
                    $quantity,
                    $parameters,
                    $position,
                );
                if ($duplicate->request_fingerprint === null
                    || ! hash_equals($duplicate->request_fingerprint, $requestFingerprint)) {
                    throw new CommandRequestConflictException;
                }

                return [
                    'queue' => $queue,
                    'item' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus($user->id, DailyQuestService::COMMAND_REGISTERED),
                ];
            }

            $definition = CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)
                ->where('key', $commandKey)
                ->where('enabled', true)
                ->first();
            $undergroundDefinition = $this->undergroundCommands->find($ruleset->settings, $commandKey);
            if ($definition === null && $undergroundDefinition === null) {
                throw new PlayerFacingCommandException('利用できないcommandです。');
            }
            if ($definition !== null && $undergroundDefinition !== null) {
                throw new DomainException('Surface and Underground command keys must be disjoint.');
            }
            $definition ??= $undergroundDefinition;

            if ($definition->key !== 'scuttle_ship' && $targetShipId !== null) {
                throw new PlayerFacingCommandException('このcommandへShip IDを指定することはできません。');
            }

            $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
            if ($definition instanceof UndergroundCommandDefinition) {
                if ($quantity !== 1 || $parameters !== []) {
                    throw new PlayerFacingCommandException('地下施設commandは数量や追加parameterを指定できません。');
                }
            } else {
                $this->quantitySemantics->validateForRegistration($definition, $quantity, $quantityProvided);
                $schemas = $definition->metadata['parameters'] ?? [];
                if (! is_array($schemas)) {
                    throw new DomainException('command parameter schemaが不正です。');
                }
                $parameters = $this->parameters->validate($schemas, $parameters);
            }
            $targetContext = 'surface_cell';
            if ($definition->target_type === 'underground_slot') {
                if ($targetX !== null || $targetY !== null || ! is_int($targetLayer) || ! is_int($targetSlotIndex)) {
                    throw new PlayerFacingCommandException('地下施設commandにはlayerとslot_indexだけを指定してください。');
                }
                $this->undergroundFacilities->assertEntitled($user, $lockedNation, $targetLayer, $targetSlotIndex);
                $targetContext = 'underground_slot';
            } else {
                if ($targetLayer !== null || $targetSlotIndex !== null) {
                    throw new PlayerFacingCommandException('Surface commandへ地下施設枠を指定することはできません。');
                }
                [$targetX, $targetY] = $this->resolveTargetCoordinates(
                    $lockedNation,
                    $mapSpace,
                    $definition,
                    $targetX,
                    $targetY,
                );
                if ($definition instanceof CommandDefinition && $definition->key === 'scuttle_ship') {
                    $parameters = $this->bindScuttleShipTarget(
                        $lockedNation,
                        $mapSpace,
                        $targetShipId,
                    );
                }
            }
            $requestFingerprint = $this->requestFingerprint(
                $ruleset,
                $definition,
                $targetX,
                $targetY,
                $targetLayer,
                $targetSlotIndex,
                $quantity,
                $parameters,
                $position,
            );
            $this->assertVersion($queue, $expectedVersion);

            $activeItems = $this->lockedQueuedItems($queue);
            $limit = $this->queueLimit($world);
            if ($activeItems->count() >= $limit) {
                throw new PlayerFacingCommandException("command queueの上限{$limit}件に達しています。");
            }
            if ($activeItems->contains(static fn (NationCommandQueueItem $item): bool => $item->queue_position === null || $item->queue_position < 1 || $item->queue_position > $limit
            )) {
                $this->queueCompactor->compact($queue);
                $activeItems = $this->lockedQueuedItems($queue);
            }
            $position ??= $this->firstAutomaticPosition($activeItems->pluck('queue_position')->all(), $limit);
            if ($position < 1 || $position > $limit) {
                throw new PlayerFacingCommandException("挿入位置は1から{$limit}の範囲で指定してください。");
            }

            // A queue item is a future plan. Registration proves only that the
            // coordinate and parameters are structurally valid; the locked
            // target state and assets are revalidated immediately before execution.
            if ($definition instanceof CommandDefinition) {
                $this->nationTargets->validateRegistration($lockedNation, $definition, $parameters);
            }
            $queue->setRelation('items', $activeItems);
            if ($targetContext === 'underground_slot') {
                $projectedFacility = $this->undergroundFacilities->projectedFacilityKey(
                    $queue,
                    $targetLayer,
                    $targetSlotIndex,
                    $position,
                );
                $this->undergroundFacilities->assertProjectedCommand($definition, $projectedFacility);
            } else {
                $target = $this->targetCell($mapSpace, $targetX, $targetY);
                $this->underseaConstruction->costMultiplier($lockedNation, $definition, $target, $parameters, true);
                if (SettlementOverbuildPolicy::protectsCapital($definition->key, $target->facility?->key, $definition->metadata)) {
                    throw new PlayerFacingCommandException('首都を通常建設commandで上書きすることはできません。');
                }
                $projectionMemo = new SurfaceCommandProjectionMemo;
                $projectedTarget = $this->projectCellStateBeforePosition(
                    $target,
                    $queue,
                    $position,
                    $lockedNation,
                    $mapSpace,
                    projectionMemo: $projectionMemo,
                );
                if ($definition instanceof CommandDefinition) {
                    $this->assertFacilityExpansionRegistration(
                        $definition,
                        $target,
                        $projectedTarget,
                        $ruleset->settings,
                        $queue,
                        $position,
                        $lockedNation,
                        $mapSpace,
                        $projectionMemo,
                    );
                    $maximum = $this->centralFacilityLimit->reachedMaximum(
                        $ruleset->settings,
                        $lockedNation,
                        $definition,
                        $target,
                    );
                    if ($maximum !== null) {
                        throw new PlayerFacingCommandException("中央施設は1島に同じ種類を{$maximum}個まで建設できます。");
                    }
                }
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
                'command_definition_id' => $definition instanceof CommandDefinition ? $definition->id : null,
                'queue_position' => $position,
                'target_context' => $targetContext,
                'underground_command_key' => $definition instanceof UndergroundCommandDefinition
                    ? $definition->key
                    : null,
                'target_x' => $targetX,
                'target_y' => $targetY,
                'target_layer' => $targetLayer,
                'target_slot_index' => $targetSlotIndex,
                'quantity' => $quantity,
                'parameters' => $parameters,
                'status' => 'queued',
                'request_ruleset_version_id' => $ruleset->id,
            ]);
            if ($definition instanceof CommandDefinition) {
                $proposedItem->setRelation('definition', $definition);
            }
            $proposedItem->setRelation('requestRulesetVersion', $ruleset);
            $proposedItems->push($proposedItem);
            if ($targetContext === 'underground_slot') {
                $this->undergroundFacilities->assertProjectedSequences($queue, $proposedItems);
            }
            if ($targetContext === 'surface_cell') {
                $this->assertNoNewDangerousOverbuildEffects(
                    $queue,
                    $activeItems,
                    $proposedItems,
                    $lockedNation,
                    $mapSpace,
                );
            }
            if ($shifted->isNotEmpty()) {
                NationCommandQueueItem::query()->whereIn('id', $shifted->modelKeys())
                    ->update(['queue_position' => null]);
                foreach ($shifted->sortByDesc('queue_position') as $shiftedItem) {
                    NationCommandQueueItem::query()->whereKey($shiftedItem->id)
                        ->update(['queue_position' => (int) $shiftedItem->queue_position + 1]);
                }
            }

            if ($definition instanceof CommandDefinition
                && $definition->ruleset_version_id !== $world->ruleset_version_id) {
                throw new DomainException('Command definition no longer matches the locked World ruleset.');
            }

            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition instanceof CommandDefinition ? $definition->id : null,
                'underground_command_key' => $definition instanceof UndergroundCommandDefinition ? $definition->key : null,
                'request_ruleset_version_id' => $ruleset->id,
                'queue_position' => $position,
                'target_context' => $targetContext,
                'target_x' => $targetX,
                'target_y' => $targetY,
                'target_layer' => $targetLayer,
                'target_slot_index' => $targetSlotIndex,
                'quantity' => $quantity,
                'parameters' => $parameters === [] ? (object) [] : $parameters,
                'status' => 'queued',
                'queued_by_membership_id' => $membership->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $requestFingerprint,
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            $queue->increment('version');
            $queue->refresh();
            $dispatchOption = $definition instanceof CommandDefinition && $definition->key === 'monster_dispatch'
                && ($definition->metadata['quantity_selects_catalog'] ?? null) === MonsterDispatchOptionResolver::CATALOG
                    ? $this->monsterDispatchOptions->resolve($definition, $quantity)
                    : null;
            $this->audit($user, 'command.queued', $item, array_filter([
                'command_key' => $commandKey,
                'x' => $targetX,
                'y' => $targetY,
                'layer' => $targetLayer,
                'slot_index' => $targetSlotIndex,
                'quantity' => $quantity,
                'monster_key' => $dispatchOption?->monsterDefinitionKey,
                'cost_money' => $dispatchOption?->costMoney,
                'request_ruleset_version_id' => $ruleset->id,
            ], static fn (mixed $value): bool => $value !== null));

            return [
                'queue' => $queue,
                'item' => $item,
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordCommandRegistered($user->id, 'item:'.$item->id),
            ];
        }, 3);
    }

    /** @param array<string, mixed> $parameters */
    private function requestFingerprint(
        RulesetVersion $ruleset,
        CommandDefinition|UndergroundCommandDefinition $definition,
        ?int $targetX,
        ?int $targetY,
        ?int $targetLayer,
        ?int $targetSlotIndex,
        int $quantity,
        array $parameters,
        ?int $requestedPosition,
    ): string {
        $payload = [
            'command_key' => $definition->key,
            'parameters' => $this->canonicalizeFingerprintValue($parameters),
            'quantity' => $quantity,
            'requested_position' => $requestedPosition,
            'ruleset' => ['key' => $ruleset->key, 'version' => $ruleset->version],
            'target_x' => $targetX,
            'target_y' => $targetY,
        ];
        if ($definition instanceof UndergroundCommandDefinition) {
            $payload['target_layer'] = $targetLayer;
            $payload['target_slot_index'] = $targetSlotIndex;
        }

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
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
            $activeItems = $this->lockedQueuedItems($queue);
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
            $positionsById = [];
            foreach ($placements as $placement) {
                $positionsById[$placement['id']] = (int) $placement['position'];
            }

            return $this->applyPositioning($user, $lockedNation, $queue, $activeItems, $positionsById, ['placements' => $placements]);
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
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new PlayerFacingCommandException('編集できないcommandです。');
            }
            $item->loadMissing('definition');
            if ($item->target_context === 'underground_slot') {
                throw new PlayerFacingCommandException('地下施設commandの数量は変更できません。');
            }
            $definition = $this->surfaceDefinitionForItem($item, $world->rulesetVersion);
            $this->quantitySemantics->assertEditable($definition);
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
            $activeItems = $this->lockedQueuedItems($queue);
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
            $positionsById = [];
            foreach ($orderedIds as $index => $id) {
                $positionsById[$id] = $index + 1;
            }

            return $this->applyPositioning($user, $lockedNation, $queue, $activeItems, $positionsById, ['ordered_ids' => $orderedIds]);
        }, 3);
    }

    /** @return Collection<int, NationCommandQueueItem> */
    private function lockedQueuedItems(NationCommandQueue $queue): Collection
    {
        return NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->with('definition')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $activeItems
     * @param  array<int, int>  $positionsById
     * @param  array<string, mixed>  $auditMetadata
     */
    private function applyPositioning(
        User $user,
        Nation $nation,
        NationCommandQueue $queue,
        Collection $activeItems,
        array $positionsById,
        array $auditMetadata,
    ): NationCommandQueue {
        $proposedItems = $activeItems->map(static function (NationCommandQueueItem $item) use ($positionsById): NationCommandQueueItem {
            $proposed = clone $item;
            $proposed->queue_position = $positionsById[$item->id];

            return $proposed;
        });
        $this->undergroundFacilities->assertProjectedSequences($queue, $proposedItems);
        $this->assertNoNewDangerousOverbuildEffects(
            $queue,
            $activeItems,
            $proposedItems,
            $nation,
            MapSpace::query()->findOrFail($queue->map_space_id),
        );

        if ($positionsById !== []) {
            NationCommandQueueItem::query()->whereIn('id', array_keys($positionsById))
                ->update(['queue_position' => null]);
            foreach ($positionsById as $id => $position) {
                NationCommandQueueItem::query()->whereKey($id)->update(['queue_position' => $position]);
            }
        }
        $queue->increment('version');
        $queue->refresh();
        $this->audit($user, 'command.reordered', $queue, $auditMetadata);

        return $queue;
    }

    public function cancel(User $user, Nation $nation, NationCommandQueueItem $item, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $item, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            [$lockedNation] = $this->lockActiveOwnerAfterWorld($user, $nation, $world);
            $queue = NationCommandQueue::query()->where('nation_id', $lockedNation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new PlayerFacingCommandException('取消できないcommandです。');
            }
            $activeItems = $this->lockedQueuedItems($queue);
            $nextPosition = 1;
            $proposedItems = $activeItems
                ->reject(static fn (NationCommandQueueItem $activeItem): bool => $activeItem->id === $item->id)
                ->map(static function (NationCommandQueueItem $activeItem) use (&$nextPosition): NationCommandQueueItem {
                    $proposed = clone $activeItem;
                    $proposed->queue_position = $nextPosition++;

                    return $proposed;
                })->values();
            $this->undergroundFacilities->assertProjectedSequences($queue, $proposedItems);
            $this->assertNoNewDangerousOverbuildEffects(
                $queue,
                $activeItems,
                $proposedItems,
                $lockedNation,
                MapSpace::query()->findOrFail($queue->map_space_id),
            );
            $item->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
            $this->queueCompactor->compact($queue);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.cancelled', $item, []);

            return $queue;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function bulkPreview(User $user, Nation $nation, MapSpace $mapSpace, string $action, int $position, int $expectedVersion): array
    {
        return $this->bulkInsert($user, $nation, $mapSpace, $action, $position, (string) Str::uuid(), $expectedVersion, previewOnly: true);
    }

    /** @return array<string, mixed> */
    public function bulkInsert(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        string $action,
        int $position,
        string $requestKey,
        int $expectedVersion,
        string $overflowPolicy = 'overflow',
        ?string $previewToken = null,
        bool $previewOnly = false,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $action, $position, $requestKey, $expectedVersion, $overflowPolicy, $previewToken, $previewOnly): array {
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
            if (! in_array($overflowPolicy, ['overflow', 'fill_available'], true)) {
                throw new PlayerFacingCommandException('利用できない溢れ方針です。');
            }

            $queue = NationCommandQueue::query()->firstOrCreate(
                ['nation_id' => $lockedNation->id],
                ['map_space_id' => $mapSpace->id, 'version' => 1],
            );
            $queue = NationCommandQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->map_space_id !== $mapSpace->id) {
                throw new DomainException('queueとmap spaceが一致しません。');
            }

            $claims = null;
            $requestFingerprint = null;
            if ($previewToken !== null) {
                try {
                    $claims = json_decode(Crypt::decryptString($previewToken), true, 512, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    throw new BulkPlanPreviewChangedException;
                }
                if (! is_array($claims) || ($claims['format'] ?? null) !== 1
                    || ($claims['actor_id'] ?? null) !== $user->id || ($claims['nation_id'] ?? null) !== $nation->id
                    || ($claims['map_space_id'] ?? null) !== $mapSpace->id || ($claims['action'] ?? null) !== $action
                    || ($claims['position'] ?? null) !== $position || ($claims['request_key'] ?? null) !== $requestKey
                    || ($claims['expected_version'] ?? null) !== $expectedVersion) {
                    throw new BulkPlanPreviewChangedException;
                }
                $requestFingerprint = hash('sha256', $previewToken.':'.$overflowPolicy);
            }

            $firstDerivedRequestKey = $this->derivedBulkRequestKey($requestKey, 0);
            $completedRequest = DB::table('nation_command_queue_bulk_requests')
                ->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $requestKey)
                ->first(['candidate_count', 'inserted_count', 'truncated_count', 'request_fingerprint']);
            if ($completedRequest !== null) {
                if ($completedRequest->request_fingerprint !== $requestFingerprint) {
                    throw new CommandRequestConflictException;
                }
                $insertedCount = (int) $completedRequest->inserted_count;

                return [
                    'queue' => $queue,
                    'inserted_count' => $insertedCount,
                    'truncated_count' => (int) $completedRequest->truncated_count,
                    'candidate_count' => (int) $completedRequest->candidate_count,
                    'cancelled_count' => max(0, (int) $completedRequest->truncated_count - ((int) $completedRequest->candidate_count - $insertedCount)),
                    'skipped_count' => (int) $completedRequest->candidate_count - $insertedCount,
                    'duplicate' => true,
                    'daily_quest' => $insertedCount > 0
                        ? $this->dailyQuests->currentStatus($user->id, DailyQuestService::COMMAND_REGISTERED)
                        : null,
                ];
            }
            if ($claims !== null && ($claims['expires_at'] ?? 0) <= now()->timestamp) {
                throw new BulkPlanPreviewChangedException;
            }
            if (NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $firstDerivedRequestKey)->exists()) {
                return [
                    'queue' => $queue,
                    'inserted_count' => 0,
                    'truncated_count' => 0,
                    'candidate_count' => 0,
                    'duplicate' => true,
                    'daily_quest' => null,
                ];
            }
            $this->assertVersion($queue, $expectedVersion);

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
                ->orderBy('y')->orderBy('x')->lockForUpdate()->with(['terrain', 'facility'])->withExists('ship')->get();

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
            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)->where('status', 'queued')
                ->orderBy('queue_position')->orderBy('id')->with('definition')->lockForUpdate()->get();
            $snapshot = hash('sha256', json_encode([
                $world->ruleset_version_id, $queue->version, $limit,
                $activeItems->map(fn (NationCommandQueueItem $item): array => $item->getAttributes())->all(),
                array_map(static fn (array $candidate): array => [$candidate['definition']->id, $candidate['x'], $candidate['y']], $candidates),
            ], JSON_THROW_ON_ERROR));
            $plans = [];
            foreach (['overflow', 'fill_available'] as $policy) {
                $plans[$policy] = $this->bulkPlan($activeItems, $candidates, $position, $limit, str_starts_with($action, 'reclaim_') ? 2 : 1, $policy);
            }
            if ($previewOnly) {
                $expiresAt = now()->addHour()->timestamp;
                $previewClaims = [
                    'format' => 1, 'actor_id' => $user->id, 'nation_id' => $nation->id, 'map_space_id' => $mapSpace->id,
                    'action' => $action, 'position' => $position, 'request_key' => $requestKey,
                    'expected_version' => $queue->version, 'expires_at' => $expiresAt, 'snapshot' => $snapshot,
                ];

                return [
                    'preview_token' => Crypt::encryptString(json_encode($previewClaims, JSON_THROW_ON_ERROR)),
                    'request_key' => $requestKey, 'expected_version' => $queue->version, 'expires_at' => $expiresAt,
                    'candidate_count' => count($candidates), 'available_count' => max(0, $limit - $activeItems->count()),
                    'queue_limit' => $limit, 'overflow_required' => count($candidates) > max(0, $limit - $activeItems->count()),
                    'policies' => array_map(static fn (array $plan): array => [
                        'inserted_count' => $plan['inserted_count'], 'cancelled_count' => $plan['cancelled_count'],
                        'skipped_count' => $plan['skipped_count'],
                        'cancelled_items' => $plan['cancelled_items'],
                    ], $plans),
                ];
            }
            if ($claims !== null) {
                if (! hash_equals($claims['snapshot'], $snapshot)) {
                    throw new BulkPlanPreviewChangedException;
                }
            }
            if ($candidates === []) {
                $this->recordBulkRequest($queue, $requestKey, $action, $position, 0, 0, 0, $requestFingerprint);

                return [
                    'queue' => $queue,
                    'inserted_count' => 0,
                    'truncated_count' => 0,
                    'candidate_count' => 0,
                    'cancelled_count' => 0,
                    'skipped_count' => 0,
                    'duplicate' => false,
                    'daily_quest' => null,
                ];
            }

            $plan = $plans[$overflowPolicy];
            $kept = $plan['kept'];
            $dropped = $plan['dropped'];
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
                    'request_ruleset_version_id' => $world->ruleset_version_id,
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
                $requestFingerprint,
            );

            return [
                'queue' => $queue,
                'inserted_count' => $insertedCount,
                'truncated_count' => count($dropped),
                'candidate_count' => count($candidates),
                'cancelled_count' => $plan['cancelled_count'],
                'skipped_count' => $plan['skipped_count'],
                'duplicate' => false,
                'daily_quest' => $insertedCount > 0
                    ? $this->dailyQuests->recordCommandRegistered($user->id, 'bulk:'.$queue->id.':'.$requestKey)
                    : null,
            ];
        }, 3);
    }

    /**
     * @param  Collection<int, NationCommandQueueItem>  $activeItems
     * @param  list<array{definition: CommandDefinition, x: int, y: int}>  $candidates
     * @return array<string, mixed>
     */
    private function bulkPlan(Collection $activeItems, array $candidates, int $position, int $limit, int $groupSize, string $policy): array
    {
        $prefix = $activeItems->filter(fn (NationCommandQueueItem $item): bool => (int) $item->queue_position < $position)->values()->all();
        $suffix = $activeItems->filter(fn (NationCommandQueueItem $item): bool => (int) $item->queue_position >= $position)->values()->all();
        $capacity = $policy === 'fill_available' ? max(0, $limit - $activeItems->count()) : max(0, $limit - count($prefix));
        $inserted = min(count($candidates), $capacity);
        $inserted -= $inserted % $groupSize;
        $suffixCount = max(0, $limit - count($prefix) - $inserted);
        $existing = static fn (NationCommandQueueItem $item): array => ['existing' => $item];
        $generated = static fn (array $candidate): array => ['generated' => $candidate];

        return [
            'kept' => [...array_map($existing, $prefix), ...array_map($generated, array_slice($candidates, 0, $inserted)), ...array_map($existing, array_slice($suffix, 0, $suffixCount))],
            'dropped' => [...array_map($generated, array_slice($candidates, $inserted)), ...array_map($existing, array_slice($suffix, $suffixCount))],
            'inserted_count' => $inserted, 'skipped_count' => count($candidates) - $inserted,
            'cancelled_count' => max(0, count($suffix) - $suffixCount),
            'cancelled_items' => array_map(static fn (NationCommandQueueItem $item): array => [
                'id' => $item->id, 'queue_position' => (int) $item->queue_position,
            ], array_slice($suffix, $suffixCount)),
        ];
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
            $items = NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')->where('queue_position', '>=', $position)
                ->orderBy('queue_position')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
                $this->audit($user, 'command.cancelled', $item, ['reason' => 'cancel_from_position']);
            }
            if ($items->isNotEmpty()) {
                $this->queueCompactor->compact($queue);
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
        ?string $requestFingerprint = null,
    ): void {
        DB::table('nation_command_queue_bulk_requests')->insert([
            'nation_command_queue_id' => $queue->id,
            'request_key' => $requestKey,
            'action' => $action,
            'position' => $position,
            'candidate_count' => $candidateCount,
            'inserted_count' => $insertedCount,
            'truncated_count' => $truncatedCount,
            'request_fingerprint' => $requestFingerprint,
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

        return $queue;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}|null  $initialState
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}
     */
    public function projectCellStateBeforePosition(
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
        MapSpace $mapSpace,
        ?array $initialState = null,
        ?SurfaceCommandProjectionMemo $projectionMemo = null,
        ?RulesetVersion $currentRuleset = null,
    ): array {
        $projectionMemo ??= new SurfaceCommandProjectionMemo;
        $landProjection = $projectionMemo->landProjection($this->landProjectionKey($queue, $nation, $mapSpace));
        if ($initialState === null && $landProjection?->canResolve($beforePosition)) {
            $projected = $landProjection->stateBefore($cell->x, $cell->y, $beforePosition);
            if ($projected !== null) {
                return $projected;
            }
        }
        $state = $initialState ?? [
            'terrain_key' => $cell->terrain->key,
            'facility_key' => $cell->facility?->key,
            'owner_nation_id' => $cell->owner_nation_id,
            'facility_operational_state' => $cell->facility_operational_state,
        ];
        $memoKey = implode(':', [
            spl_object_id($queue),
            $cell->getKey(),
            $beforePosition,
            $state['terrain_key'],
            $state['facility_key'] ?? '-',
            $state['owner_nation_id'] ?? '-',
            $state['facility_operational_state'] ?? '-',
        ]);
        $cached = $projectionMemo->get($memoKey);
        if ($cached !== null) {
            return $cached;
        }

        $currentRuleset ??= $this->currentRulesetForNation($nation, $projectionMemo);
        $projectionMemo->putCurrentRuleset($nation->id, $currentRuleset);
        foreach ($queue->items as $item) {
            if ($item->target_context !== 'surface_cell'
                || $item->queue_position >= $beforePosition
                || $item->target_x !== $cell->x
                || $item->target_y !== $cell->y) {
                continue;
            }
            $state = $this->projectQueuedCellResult($item, $state, $cell, $queue, $nation, $mapSpace, $projectionMemo, $currentRuleset);
        }

        $projectionMemo->put($memoKey, $state);

        return $state;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}  $state
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}
     */
    private function projectQueuedCellResult(NationCommandQueueItem $item, array $state, MapCell $cell, NationCommandQueue $queue, Nation $nation, MapSpace $mapSpace, SurfaceCommandProjectionMemo $projectionMemo, RulesetVersion $currentRuleset): array
    {
        $definition = $this->surfaceDefinitionForItem($item, $currentRuleset);
        // Ship movement is resolved by the Turn, so its construction is not a fixed-cell forecast.
        if (array_key_exists('construction_ship_id', $item->parameters)) {
            return $state;
        }
        if ($definition->target_type === 'cell' && $definition->key !== 'territory_expand'
            && ! in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            $occupied = $projectionMemo->monsterOccupied($cell->id);
            if ($occupied === null) {
                $exists = $cell->getAttribute('monster_occupancy_exists');
                $occupied = $exists === null
                    ? MonsterOccupancy::query()->where('map_cell_id', $cell->id)->exists()
                    : (bool) $exists;
                $projectionMemo->putMonsterOccupied($cell->id, $occupied);
            }
            if ($occupied) {
                return $state;
            }
        }
        $matches = $definition->key === 'territory_expand'
            ? $this->projectedTerritoryTargetMatches($definition, $state, $cell, $queue, (int) $item->queue_position, $nation, $mapSpace, $projectionMemo)
            : $this->projectedTargetMatches($definition, $state, $nation, $mapSpace, $cell, $queue, (int) $item->queue_position, $projectionMemo, $item->parameters);

        return $matches ? $this->applyProjectedResult($definition, $state, $nation) : $state;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}  $state
     * @param  array<string, mixed>  $parameters
     */
    public function projectedTargetMatches(
        CommandDefinition $definition,
        array $state,
        Nation $nation,
        MapSpace $mapSpace,
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        ?SurfaceCommandProjectionMemo $projectionMemo = null,
        array $parameters = [],
    ): bool {
        $projectionMemo ??= new SurfaceCommandProjectionMemo;
        try {
            $this->underseaConstruction->costMultiplier($nation, $definition, $cell, $parameters);
        } catch (PlayerFacingCommandException) {
            return false;
        }
        if ($definition->key === 'scuttle_ship') {
            return false;
        }
        if (! in_array($state['terrain_key'], $definition->target_terrain_keys, true)) {
            return false;
        }
        if (CapitalDestructionPolicy::protects($definition->key, $state['facility_key'])) {
            return false;
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $state['facility_key'], $definition->metadata)) {
            return false;
        }
        if ($definition->requires_empty_facility && $state['facility_key'] !== null
            && ! WindPowerRepairPolicy::matches($definition, $state['facility_key'], $state['facility_operational_state'])
            && ! SettlementOverbuildPolicy::allows($definition->key, $state['facility_key'], $definition->metadata)
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
            && $state['terrain_key'] === 'sea'
            && $state['facility_key'] !== null) {
            return false;
        }
        if ($definition->key === 'build_port') {
            $adjacent = $this->projectedPortAdjacentFacts(
                $nation,
                $mapSpace,
                $cell,
                $queue,
                $beforePosition,
                $projectionMemo,
            );

            return $state['owner_nation_id'] === null && $adjacent['owned_land'] && $adjacent['sea'];
        }
        if ($this->underseaConstruction->appliesTo($definition)
            || LandDevelopmentDistancePolicy::requirement($definition, $state['terrain_key'], $parameters) !== null) {
            return ($state['owner_nation_id'] === null || $state['owner_nation_id'] === $nation->id)
                && $this->projectedLandDistanceReasonCode($nation, $mapSpace, $definition, $cell, $state['terrain_key'], $queue, $beforePosition, $projectionMemo, $parameters) === null
                && ($definition->key !== 'reclaim' || ! $this->projectedForeignAdjacentCell($nation, $mapSpace, $cell, $queue, $beforePosition, $projectionMemo));
        }
        if ($definition->key === 'excavate') {
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
        ?SurfaceCommandProjectionMemo $projectionMemo = null,
    ): bool {
        $projectionMemo ??= new SurfaceCommandProjectionMemo;
        $neighbors = $this->projectedNeighborCells($mapSpace, $cell, $projectionMemo);
        $adjacentActorTerritory = $neighbors->contains(function (MapCell $neighbor) use ($queue, $beforePosition, $nation, $mapSpace, $projectionMemo): bool {
            $projected = $this->projectCellStateBeforePosition(
                $neighbor,
                $queue,
                $beforePosition,
                $nation,
                $mapSpace,
                projectionMemo: $projectionMemo,
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
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}  $state
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}
     */
    private function applyProjectedResult(CommandDefinition $definition, array $state, Nation $nation): array
    {
        $ownerOverbuildEffect = $this->projectedOwnerOverbuildEffect($definition, $nation, $state);
        if ($ownerOverbuildEffect === 'defense_self_destruct') {
            $state['terrain_key'] = 'sea';
            $state['facility_key'] = null;
            $state['facility_operational_state'] = null;
            $state['owner_nation_id'] = null;

            return $state;
        }
        if ($ownerOverbuildEffect === 'monument_flight') {
            $state['terrain_key'] = 'wasteland';
            $state['facility_key'] = null;
            $state['facility_operational_state'] = null;

            return $state;
        }

        if ($definition->key === 'reclaim') {
            $wasSea = $state['terrain_key'] === 'sea';
            $state['terrain_key'] = $wasSea ? 'shallow' : 'wasteland';
            $state['owner_nation_id'] = $wasSea ? null : $nation->id;
        } elseif ($definition->key === 'excavate') {
            $state['terrain_key'] = match ($state['terrain_key']) {
                'sea' => 'sea',
                'shallow' => 'sea',
                'mountain' => 'wasteland',
                default => 'shallow',
            };
            $state['facility_key'] = null;
            $state['facility_operational_state'] = null;
            if (in_array($state['terrain_key'], ['sea', 'shallow'], true)) {
                $state['owner_nation_id'] = null;
            }
        } else {
            if ($definition->result_terrain_key !== null) {
                $state['terrain_key'] = $definition->result_terrain_key;
            }
            if ($definition->result_facility_key !== null) {
                $state['facility_key'] = $definition->result_facility_key;
                $state['facility_operational_state'] = 'operational';
            }
        }

        if (in_array($definition->key, ['land_clear', 'land_level', 'logging', 'plant_forest'], true)) {
            $state['facility_key'] = null;
            $state['facility_operational_state'] = null;
        }
        if ($definition->key === 'territory_expand') {
            $state['owner_nation_id'] = $nation->id;
        } elseif ($definition->key === 'build_port' || $this->underseaConstruction->appliesTo($definition)) {
            $state['owner_nation_id'] = $nation->id;
        } elseif ($definition->key === 'territory_abandon') {
            $state['owner_nation_id'] = null;
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
                    'command_definition_id' => $candidate['definition']->id,
                    'target_context' => 'surface_cell',
                    'target_x' => $candidate['x'],
                    'target_y' => $candidate['y'],
                    'parameters' => [],
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
        $currentRuleset = $this->currentRulesetForNation($nation);
        try {
            $before = $this->projectedOwnerOverbuildEffectsByItem(
                $queue,
                $currentItems,
                $nation,
                $mapSpace,
                $currentRuleset,
            );
            $after = $this->projectedOwnerOverbuildEffectsByItem(
                $queue,
                $proposedItems,
                $nation,
                $mapSpace,
                $currentRuleset,
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
        RulesetVersion $currentRuleset,
    ): array {
        $queue->setRelation(
            'items',
            $items->sortBy(static fn (NationCommandQueueItem $item): int => (int) $item->queue_position)->values(),
        );
        $effects = [];
        foreach ($queue->items as $item) {
            if (! $item->exists || $item->target_context !== 'surface_cell') {
                continue;
            }
            $definition = $this->surfaceDefinitionForItem($item, $currentRuleset);
            $declaredEffect = $definition->metadata['owner_overbuild_effect'] ?? null;
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
                currentRuleset: $currentRuleset,
            );
            $effects[$item->id] = $this->projectedOwnerOverbuildEffect($definition, $nation, $state);
        }

        return $effects;
    }

    public function definitionForItem(
        NationCommandQueueItem $item,
        ?RulesetVersion $currentRuleset = null,
    ): CommandDefinition|UndergroundCommandDefinition {
        $definition = $this->queuedCommandDefinitions->resolve($item, $currentRuleset);
        if ($definition instanceof CommandDefinition && $currentRuleset !== null) {
            $definition->setRelation('rulesetVersion', $currentRuleset);
        }

        return $definition;
    }

    private function surfaceDefinitionForItem(NationCommandQueueItem $item, RulesetVersion $currentRuleset): CommandDefinition
    {
        $definition = $this->definitionForItem($item, $currentRuleset);

        return $definition instanceof CommandDefinition
            ? $definition
            : throw new DomainException('Surface queue item command identity is invalid.');
    }

    private function currentRulesetForNation(Nation $nation, ?SurfaceCommandProjectionMemo $projectionMemo = null): RulesetVersion
    {
        $ruleset = $projectionMemo?->currentRuleset($nation->id);
        if ($ruleset === null) {
            $ruleset = $nation->world()->with('rulesetVersion')->firstOrFail()->rulesetVersion;
            $projectionMemo?->putCurrentRuleset($nation->id, $ruleset);
        }

        return $ruleset;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}|null  $visibleState
     * @param  array<string, mixed>|null  $rulesetSettings
     * @param  array<string, mixed>  $parameters
     */
    public function validateTarget(
        Nation $nation,
        MapSpace $mapSpace,
        CommandDefinition $definition,
        MapCell $cell,
        ?array $visibleState = null,
        ?array $rulesetSettings = null,
        array $parameters = [],
        ?SurfaceCommandProjectionMemo $projectionMemo = null,
    ): void {
        $this->underseaConstruction->costMultiplier($nation, $definition, $cell, $parameters);
        $state = $visibleState ?? [
            'terrain_key' => $cell->terrain->key,
            'facility_key' => $cell->facility?->key,
            'owner_nation_id' => $cell->owner_nation_id,
            'facility_operational_state' => $cell->facility_operational_state,
        ];
        $terrainKey = $state['terrain_key'];
        $facilityKey = $state['facility_key'];
        $ownerNationId = $state['owner_nation_id'];
        $ownerOverbuildEffect = $visibleState === null
            ? OwnerFacilityOverbuildPolicy::effect($definition, $nation, $cell)
            : OwnerFacilityOverbuildPolicy::effectForState($definition, $nation, $state);
        if ($definition->key === 'scuttle_ship') {
            $ship = $cell->relationLoaded('ship')
                ? $cell->ship
                : $cell->ship()->first();
            if (! $ship instanceof Ship
                || $ship->world_id !== $nation->world_id
                || $ship->nation_id !== $nation->id) {
                throw new PlayerFacingCommandException('自国の船を選択してください。');
            }

            return;
        }
        if ($definition->key === 'territory_expand') {
            $this->validateTerritoryExpansionState(
                $nation,
                $mapSpace,
                $definition,
                $cell,
                [
                    'terrain_key' => $terrainKey,
                    'facility_key' => $facilityKey,
                    'owner_nation_id' => $ownerNationId,
                ],
                $ownerNationId !== $nation->id && $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 1, false),
            );

            return;
        }
        if (! in_array($terrainKey, $definition->target_terrain_keys, true)) {
            throw new PlayerFacingCommandException('対象地形ではこのcommandをqueueへ追加できません。');
        }
        if (CapitalDestructionPolicy::protects($definition->key, $facilityKey)) {
            throw new PlayerFacingCommandException('首都を破壊するcommandは実行できません。');
        }
        $facilityExpansion = false;
        if ($this->facilityExpansionCommand($definition)
            && $facilityKey === $definition->result_facility_key
            && $cell->facility?->key === $facilityKey) {
            $rulesetSettings ??= $this->rulesetSettingsForMapSpace($mapSpace);
            if (! is_int($cell->facility_scale)) {
                throw new PlayerFacingCommandException('施設の規模情報が不完全です。');
            }
            $maximumScale = $this->facilityRanks->maximumScale($rulesetSettings, $cell->facility);
            if ($cell->facility_scale >= $maximumScale) {
                throw new PlayerFacingCommandException('施設の規模が上限に達しています。');
            }
            $facilityExpansion = true;
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $facilityKey, $definition->metadata)) {
            throw new PlayerFacingCommandException('首都を通常建設commandで上書きすることはできません。');
        }
        if ($definition->requires_empty_facility && $facilityKey !== null
            && ! WindPowerRepairPolicy::matches($definition, $facilityKey, $state['facility_operational_state'])
            && ! SettlementOverbuildPolicy::allows($definition->key, $facilityKey, $definition->metadata)
            && ! $facilityExpansion
            && $ownerOverbuildEffect === null) {
            throw new PlayerFacingCommandException('施設のあるcellにはこのcommandをqueueへ追加できません。');
        }
        if ($definition->target_facility_keys !== [] && ! in_array($facilityKey, $definition->target_facility_keys, true)) {
            throw new PlayerFacingCommandException('対象施設ではこのcommandをqueueへ追加できません。');
        }

        if (in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            $world = $nation->world()->with('rulesetVersion')->firstOrFail();
            $targetNation = $ownerNationId === null
                ? null
                : ($ownerNationId === $cell->owner_nation_id
                    ? $cell->ownerNation
                    : Nation::query()->whereKey($ownerNationId)->first());
            if ($targetNation !== null && $targetNation->id !== $nation->id
                && ($nation->state === 'recovery' || $targetNation->state === 'recovery')) {
                throw new PlayerFacingCommandException(
                    "{$targetNation->name}へのミサイル攻撃は箱庭協定によって禁じられているため、登録できません。",
                );
            }
            $targetPolicy = MissileTargetPolicy::explicitTargetState($world->rulesetVersion->settings);
            if ($targetPolicy === MissileTargetPolicy::ANY_EXISTING_COORDINATE) {
                return;
            }
            if ($targetNation === null || $targetNation->world_id !== $world->id || $targetNation->state !== 'active') {
                throw new PlayerFacingCommandException('active Nation所有のcellだけを対象にできます。');
            }

            return;
        }

        if (in_array($definition->key, ['reclaim'], true)) {
            if ($ownerNationId !== null && $ownerNationId !== $nation->id) {
                throw new PlayerFacingCommandException('他国所有の水域は埋め立てできません。');
            }
            if (! $this->hasCurrentOwnedLandWithin($nation, $cell, LandDevelopmentDistancePolicy::RECLAMATION_RADIUS, false, $projectionMemo)) {
                throw new PlayerFacingCommandException('埋め立て対象の隣に自国陸地がありません。');
            }

            return;
        }
        if ($definition->key === 'build_port') {
            if ($ownerNationId !== null) {
                throw new PlayerFacingCommandException('港は中立の浅瀬だけに建設できます。');
            }
            $adjacent = $this->portAdjacentFacts($nation, $mapSpace, $cell);
            if (! $adjacent['owned_land']) {
                throw new PlayerFacingCommandException('港の隣に自国陸地が必要です。');
            }
            if (! $adjacent['sea']) {
                throw new PlayerFacingCommandException('港の隣に深海が必要です。');
            }

            return;
        }
        if ($this->underseaConstruction->appliesTo($definition)) {
            if ($ownerNationId !== null && $ownerNationId !== $nation->id) {
                throw new PlayerFacingCommandException('他国所有の海には建設できません。');
            }
            if (! isset($parameters['construction_ship_id'])) {
                $this->assertUnderseaConstructionDistance($nation, $cell, $projectionMemo);
            }

            return;
        }
        if ($definition->key === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true)) {
            if ($ownerNationId !== null && $ownerNationId !== $nation->id) {
                throw new PlayerFacingCommandException('他国所有の水域は掘削できません。');
            }
            if ($terrainKey === 'sea' && $facilityKey !== null) {
                throw new PlayerFacingCommandException('施設のある海では油田探索できません。');
            }
            if (! $this->hasCurrentOwnedLandWithin($nation, $cell, LandDevelopmentDistancePolicy::WATER_RADIUS, projectionMemo: $projectionMemo)) {
                throw new PlayerFacingCommandException('掘削対象の3hex以内に自国陸地がありません。');
            }

            return;
        }
        if ($ownerNationId !== $nation->id) {
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
        if ($targetOwner !== null && $targetOwner->id !== $nation->id
            && ($nation->state === 'recovery' || $targetOwner->state === 'recovery')) {
            throw new PlayerFacingCommandException('休戦中の島から、または休戦中の島の領土へ hostile な領土拡張はできません。');
        }
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

    // Mirrors the capital-core transfer rule and, for Turn execution parity, NationProtectionPolicy's dormant radius.
    private function capitalCoreProtected(World $world, GridCoordinate $target, int $newOwnerNationId): bool
    {
        $settings = $world->rulesetVersion->settings;
        $transfer = $settings['territory_transfer']['capital_core'] ?? null;
        $transferProtected = is_array($transfer) && ($transfer['ownership_transfer_protected'] ?? false) === true;
        $ownerStates = $transferProtected && is_array($transfer['owner_states'] ?? null) ? $transfer['owner_states'] : [];
        $dormantRadius = $settings['nation_lifecycle']['dormant_protection_radius'] ?? null;
        if (! is_int($dormantRadius) || $dormantRadius < 0) {
            throw new DomainException('The current Ruleset has an invalid dormant protection radius.');
        }
        $capitals = NationCapital::query()
            ->join('nations', 'nations.id', '=', 'nation_capitals.nation_id')
            ->where('nations.world_id', $world->id)
            ->whereIn('nations.state', [...$ownerStates, 'dormant'])
            ->orderBy('nation_capitals.nation_id')
            ->get(['nation_capitals.nation_id', 'nation_capitals.x', 'nation_capitals.y', 'nations.state']);
        foreach ($capitals as $capital) {
            if ($capital->getAttribute('state') === 'dormant'
                && (new GridCoordinate((int) $capital->x, (int) $capital->y))->distanceTo($target) <= $dormantRadius) {
                return true;
            }
        }
        if (! $transferProtected) {
            return false;
        }

        return $this->capitalCores->protectsTransfer(
            $target,
            $newOwnerNationId,
            $capitals
                ->filter(static fn (NationCapital $capital): bool => in_array($capital->getAttribute('state'), $ownerStates, true))
                ->map(static fn (NationCapital $capital): array => [
                    'nation_id' => (int) $capital->nation_id,
                    'x' => (int) $capital->x,
                    'y' => (int) $capital->y,
                ])->values()->all(),
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
        if ($lockedNation === null || ! in_array($lockedNation->state, ['active', 'dormant', 'recovery'], true)) {
            throw new AuthorizationException('現在の島ではないcommand queueは操作できません。');
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

    private function facilityExpansionCommand(CommandDefinition $definition): bool
    {
        return isset(self::FACILITY_BUILD_RESULTS[$definition->key])
            && self::FACILITY_BUILD_RESULTS[$definition->key] === $definition->result_facility_key;
    }

    /**
     * Registration is intentionally narrower than full execution validation:
     * only reject a same-facility build that is already at its effective limit.
     * Other future-plan state remains subject to the existing execution-time
     * revalidation contract.
     *
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $projectedState
     * @param  array<string, mixed>  $rulesetSettings
     */
    private function assertFacilityExpansionRegistration(
        CommandDefinition $definition,
        MapCell $target,
        array $projectedState,
        array $rulesetSettings,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
        MapSpace $mapSpace,
        SurfaceCommandProjectionMemo $projectionMemo,
    ): void {
        if (! $this->facilityExpansionCommand($definition)
            || $projectedState['facility_key'] !== $definition->result_facility_key
            || $target->facility?->key !== $definition->result_facility_key) {
            return;
        }
        if ($this->precedingProjectedCommandReplacesCurrentFacility(
            $target,
            $queue,
            $beforePosition,
            $nation,
            $mapSpace,
            $projectionMemo,
        )) {
            return;
        }
        $facility = $target->facility;
        if (! $facility instanceof FacilityDefinition) {
            throw new PlayerFacingCommandException('施設定義が不完全です。');
        }
        if (! is_int($target->facility_scale)) {
            throw new PlayerFacingCommandException('施設の規模情報が不完全です。');
        }
        $maximumScale = $this->facilityRanks->maximumScale($rulesetSettings, $facility);
        if ($target->facility_scale >= $maximumScale) {
            throw new PlayerFacingCommandException('施設の規模が上限に達しています。');
        }
    }

    private function precedingProjectedCommandReplacesCurrentFacility(
        MapCell $target,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
        MapSpace $mapSpace,
        SurfaceCommandProjectionMemo $projectionMemo,
    ): bool {
        $currentFacilityKey = $target->facility?->key;
        if ($currentFacilityKey === null) {
            return false;
        }

        foreach ($queue->items as $item) {
            $itemPosition = (int) $item->queue_position;
            if ($item->target_context !== 'surface_cell'
                || $itemPosition >= $beforePosition
                || $item->target_x !== $target->x
                || $item->target_y !== $target->y) {
                continue;
            }
            $stateAfterItem = $this->projectCellStateBeforePosition(
                $target,
                $queue,
                $itemPosition + 1,
                $nation,
                $mapSpace,
                projectionMemo: $projectionMemo,
            );
            if ($stateAfterItem['facility_key'] !== $currentFacilityKey) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function rulesetSettingsForMapSpace(MapSpace $mapSpace): array
    {
        $world = $mapSpace->world()->with('rulesetVersion')->firstOrFail();

        return $world->rulesetVersion->settings;
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
            ->withExists('monsterOccupancy')
            ->first();
        if ($cell === null) {
            throw new PlayerFacingCommandException('target cellが存在しません。');
        }

        return $cell;
    }

    /** @return array{ship_id: int} */
    private function bindScuttleShipTarget(Nation $nation, MapSpace $mapSpace, ?int $targetShipId): array
    {
        $ship = Ship::query()
            ->whereKey($targetShipId)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->whereNotNull('map_cell_id')
            ->whereHas('cell', static fn ($query) => $query->where('map_space_id', $mapSpace->id))
            ->lockForUpdate()
            ->first(['id']);
        if (! $ship instanceof Ship) {
            throw new PlayerFacingCommandException('自国の船を選択してください。');
        }

        return ['ship_id' => (int) $ship->id];
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

    /**
     * The terrain selects the command context; origins always use current cells.
     *
     * @param  array<string, mixed>  $parameters
     * @return 'out_of_land_range'|null
     */
    public function currentLandDistanceReasonCode(Nation $nation, CommandDefinition $definition, MapCell $cell, ?string $terrainKey = null, ?SurfaceCommandProjectionMemo $projectionMemo = null, array $parameters = []): ?string
    {
        $requirement = LandDevelopmentDistancePolicy::requirement($definition, $terrainKey ?? $cell->terrain->key, $parameters);
        if ($requirement === null) {
            return null;
        }
        [$radius, $includeCenter] = $requirement;
        $inRange = $this->hasCurrentOwnedLandWithin($nation, $cell, $radius, $includeCenter, $projectionMemo);

        return $inRange ? null : 'out_of_land_range';
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return 'out_of_land_range'|null
     */
    public function projectedLandDistanceReasonCode(Nation $nation, MapSpace $mapSpace, CommandDefinition $definition, MapCell $cell, string $terrainKey, NationCommandQueue $queue, int $beforePosition, ?SurfaceCommandProjectionMemo $projectionMemo = null, array $parameters = []): ?string
    {
        $requirement = LandDevelopmentDistancePolicy::requirement($definition, $terrainKey, $parameters);
        if ($requirement === null) {
            return null;
        }
        [$radius, $includeCenter] = $requirement;
        $projectionMemo ??= new SurfaceCommandProjectionMemo;
        $key = $this->landProjectionKey($queue, $nation, $mapSpace);
        $projection = $projectionMemo->landProjection($key);
        if ($projection === null && ($beforePosition <= 1 || $queue->items->isEmpty())) {
            return $this->hasCurrentOwnedLandWithin($nation, $cell, $radius, $includeCenter, $projectionMemo)
                ? null : 'out_of_land_range';
        }
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->radius($radius);
        if ($projection === null || ! collect($coordinates)->every(fn (GridCoordinate $point): bool => $projection->covers($point->x, $point->y))) {
            $projection = $this->buildLandProjection($nation, $mapSpace, $cell, $queue, $projectionMemo);
        }
        $inRange = $this->ownedLand->withinProjected(
            $nation,
            $cell,
            $radius,
            $includeCenter,
            fn (int $x, int $y): ?array => $projection->stateBefore($x, $y, $beforePosition),
        );

        return $inRange ? null : 'out_of_land_range';
    }

    private function landProjectionKey(NationCommandQueue $queue, Nation $nation, MapSpace $mapSpace): string
    {
        return spl_object_id($queue).':'.$nation->id.':'.$mapSpace->id;
    }

    private function projectedForeignAdjacentCell(Nation $nation, MapSpace $mapSpace, MapCell $cell, NationCommandQueue $queue, int $beforePosition, SurfaceCommandProjectionMemo $projectionMemo): bool
    {
        $projection = $projectionMemo->landProjection($this->landProjectionKey($queue, $nation, $mapSpace));
        $key = $nation->id.':'.$mapSpace->id.':'.$cell->id;
        $visibleNeighbors = $projectionMemo->visibleNeighbors($key);
        if ($projection === null && $visibleNeighbors === null) {
            $visibleNeighbors = $this->visibleProjectionStates($nation, $mapSpace, $this->projectedNeighborCells($mapSpace, $cell, $projectionMemo));
            $projectionMemo->putVisibleNeighbors($key, $visibleNeighbors);
        }
        foreach ((new GridCoordinate($cell->x, $cell->y))->neighborsWithin($mapSpace->min_x, $mapSpace->max_x, $mapSpace->min_y, $mapSpace->max_y) as $point) {
            $state = $projection === null
                ? ($visibleNeighbors[$point->x.':'.$point->y] ?? null)
                : $projection->stateBefore($point->x, $point->y, $beforePosition);
            if ($state === null) {
                continue;
            }
            if ($state['owner_nation_id'] !== null && $state['owner_nation_id'] !== $nation->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, MapCell>  $cells
     * @return array<string, array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}>
     */
    private function visibleProjectionStates(Nation $nation, MapSpace $mapSpace, Collection $cells): array
    {
        $needsSight = $cells->contains(fn (MapCell $cell): bool => $cell->owner_nation_id !== $nation->id
            && MapCellPresenter::visibleState($cell, $nation->id)['facility_key'] !== $cell->facility?->key);
        $visible = $needsSight ? $this->visibility->visibleCoordinates($mapSpace, $cells, $nation->id) : [];
        $states = [];
        foreach ($cells as $cell) {
            $key = $cell->x.':'.$cell->y;
            $states[$key] = MapCellPresenter::visibleState($cell, $nation->id, isset($visible[$key]));
        }

        return $states;
    }

    private function buildLandProjection(Nation $nation, MapSpace $mapSpace, MapCell $target, NationCommandQueue $queue, SurfaceCommandProjectionMemo $projectionMemo): SurfaceLandProjection
    {
        $currentRuleset = $this->currentRulesetForNation($nation, $projectionMemo);
        // Read only the requested radius and queued targets/dependencies, never the whole map.
        $points = [];
        $addCoordinates = static function (int $x, int $y, int $radius) use (&$points): void {
            foreach ((new GridCoordinate($x, $y))->radius($radius) as $point) {
                $points[$point->x.':'.$point->y] = $point;
            }
        };
        $addCoordinates($target->x, $target->y, max(LandDevelopmentDistancePolicy::WATER_RADIUS, LandDevelopmentDistancePolicy::RECLAMATION_RADIUS));
        foreach ($queue->items as $item) {
            if ($item->target_context !== 'surface_cell' || $item->target_x === null || $item->target_y === null) {
                continue;
            }
            $definition = $this->surfaceDefinitionForItem($item, $currentRuleset);
            $radius = LandDevelopmentDistancePolicy::requirement($definition, 'sea', $item->parameters)[0]
                ?? (in_array($definition->key, ['build_port', 'territory_expand'], true) ? 1 : 0);
            $addCoordinates($item->target_x, $item->target_y, $radius);
        }
        $rows = [];
        foreach ($points as $point) {
            $rows[$point->y][] = $point->x;
        }
        $cells = MapCell::query()->where('map_space_id', $mapSpace->id)
            ->where(function ($query) use ($rows): void {
                foreach ($rows as $y => $xs) {
                    $query->orWhere(fn ($row) => $row->where('y', $y)->whereIn('x', $xs));
                }
            })->with(['terrain', 'facility'])->withExists('monsterOccupancy')->get();
        foreach ($cells as $cell) {
            $projectionMemo->putMonsterOccupied($cell->id, (bool) $cell->getAttribute('monster_occupancy_exists'));
        }
        $projection = new SurfaceLandProjection($cells, array_fill_keys(array_keys($points), true), $this->visibleProjectionStates($nation, $mapSpace, $cells));
        $projectionMemo->putLandProjection($this->landProjectionKey($queue, $nation, $mapSpace), $projection);

        // A chronological pass resolves reclamation chains without recursive distance calls.
        foreach ($queue->items->sortBy('queue_position') as $item) {
            if ($item->target_context !== 'surface_cell' || $item->target_x === null || $item->target_y === null) {
                continue;
            }
            $cell = $projection->cell($item->target_x, $item->target_y);
            if ($cell === null) {
                continue;
            }
            $position = (int) $item->queue_position;
            $projection->advanceTo($position);
            $state = $projection->stateBefore($cell->x, $cell->y, $position);
            if ($state !== null) {
                $after = $this->projectQueuedCellResult($item, $state, $cell, $queue, $nation, $mapSpace, $projectionMemo, $currentRuleset);
                if ($after !== $state) {
                    $projection->record($cell, $position, $after);
                }
            }
        }
        $projection->finish();

        return $projection;
    }

    private function hasCurrentOwnedLandWithin(Nation $nation, MapCell $cell, int $radius, bool $includeCenter = true, ?SurfaceCommandProjectionMemo $projectionMemo = null): bool
    {
        $key = implode(':', [$nation->id, $cell->map_space_id, $cell->x, $cell->y, $radius, (int) $includeCenter]);
        $cached = $projectionMemo?->currentLandDistance($key);
        if ($cached !== null) {
            return $cached;
        }
        $inRange = $this->ownedLand->within($nation, $cell, $radius, $includeCenter);
        $projectionMemo?->putCurrentLandDistance($key, $inRange);

        return $inRange;
    }

    private function assertUnderseaConstructionDistance(Nation $nation, MapCell $cell, ?SurfaceCommandProjectionMemo $projectionMemo = null): void
    {
        if (! $this->hasCurrentOwnedLandWithin($nation, $cell, LandDevelopmentDistancePolicy::WATER_RADIUS, projectionMemo: $projectionMemo)) {
            throw new PlayerFacingCommandException('建設対象の3hex以内に自国陸地がありません。');
        }
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

    /** @return array{owned_land: bool, sea: bool} */
    private function portAdjacentFacts(Nation $nation, MapSpace $mapSpace, MapCell $cell): array
    {
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->neighborsWithin(
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        );

        return $this->portAdjacency->currentFacts($nation, (int) $mapSpace->id, $coordinates);
    }

    /** @return array{owned_land: bool, sea: bool} */
    private function projectedPortAdjacentFacts(
        Nation $nation,
        MapSpace $mapSpace,
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        SurfaceCommandProjectionMemo $projectionMemo,
    ): array {
        $neighbors = $this->projectedNeighborCells($mapSpace, $cell, $projectionMemo);
        $projected = $neighbors->map(function (MapCell $neighbor) use (
            $queue,
            $beforePosition,
            $nation,
            $mapSpace,
            $projectionMemo,
        ): array {
            return $this->projectCellStateBeforePosition(
                $neighbor,
                $queue,
                $beforePosition,
                $nation,
                $mapSpace,
                projectionMemo: $projectionMemo,
            );
        });
        $terrainWater = $projectionMemo->terrainWater();
        if ($terrainWater === null) {
            $terrainWater = TerrainDefinition::query()->pluck('is_water', 'key')
                ->map(static fn (mixed $isWater): bool => (bool) $isWater)->all();
            $projectionMemo->putTerrainWater($terrainWater);
        }

        return [
            'owned_land' => $projected->contains(
                static fn (array $state): bool => $state['owner_nation_id'] === $nation->id
                    && array_key_exists($state['terrain_key'], $terrainWater)
                    && ! $terrainWater[$state['terrain_key']],
            ),
            'sea' => $projected->contains(
                static fn (array $state): bool => $state['terrain_key'] === 'sea',
            ),
        ];
    }

    /** @return Collection<int, MapCell> */
    private function projectedNeighborCells(
        MapSpace $mapSpace,
        MapCell $cell,
        SurfaceCommandProjectionMemo $projectionMemo,
    ): Collection {
        $key = $mapSpace->getKey().':'.$cell->getKey();
        $cached = $projectionMemo->neighbors($key);
        if ($cached !== null) {
            return $cached;
        }

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
        $projectionMemo->putNeighbors($key, $neighbors);

        return $neighbors;
    }

    private function assertVersion(NationCommandQueue $queue, int $expectedVersion): void
    {
        if ($queue->version !== $expectedVersion) {
            throw new OptimisticLockException('command queueが他の操作で更新されました。再読込してください。');
        }
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
