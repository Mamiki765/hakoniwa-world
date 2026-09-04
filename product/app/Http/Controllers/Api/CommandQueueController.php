<?php

namespace App\Http\Controllers\Api;

use App\Application\CommandQuantitySemantics;
use App\Application\CommandQueueService;
use App\Application\LegacyCommandQueueOrder;
use App\Application\NationCommandTargetService;
use App\Application\Underground\UndergroundFacilityService;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\CommandRequestConflictException;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Command\SurfaceCommandProjectionMemo;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Ruleset\ResetRequiredException;
use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Domain\Underground\Facility\UndergroundCommandDefinition;
use App\Http\Controllers\Controller;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Services\MapCellPresenter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommandQueueController extends Controller
{
    public function __construct(
        private readonly LegacyCommandQueueOrder $legacyOrder,
        private readonly CommandQuantitySemantics $quantitySemantics,
        private readonly NationCommandTargetService $nationTargets,
    ) {}

    public function definitions(
        Request $request,
        Nation $nation,
        MapSpace $mapSpace,
        CommandQueueService $service,
        FacilityCapacityService $capacities,
        UndergroundFacilityService $undergroundFacilities,
        UndergroundCommandCatalog $undergroundCommands,
    ): JsonResponse {
        try {
            $queue = $service->queueFor($request->user(), $nation, $mapSpace);
            $world = $nation->world()->with('rulesetVersion')->firstOrFail();
            $position = max(1, min(
                $this->queueLimit($nation),
                $request->integer('position', 1),
            ));
            $hasLayer = $request->has('target_layer');
            $hasSlot = $request->has('target_slot_index');
            if ($hasLayer !== $hasSlot) {
                throw new PlayerFacingCommandException('地下施設枠にはlayerとslot_indexの両方が必要です。');
            }
            $undergroundTarget = $hasLayer && $hasSlot;
            $targetLayer = $undergroundTarget ? $request->integer('target_layer') : null;
            $targetSlotIndex = $undergroundTarget ? $request->integer('target_slot_index') : null;
            if ($undergroundTarget) {
                if ($request->has('target_x') || $request->has('target_y')) {
                    throw new PlayerFacingCommandException('Surface cellと地下施設枠を同時に指定できません。');
                }
                $undergroundFacilities->assertEntitled($request->user(), $nation, $targetLayer, $targetSlotIndex);
                $projectedFacility = $undergroundFacilities->projectedFacilityKey(
                    $queue,
                    $targetLayer,
                    $targetSlotIndex,
                    $position,
                );
                $commands = collect($undergroundCommands->all($world->rulesetVersion->settings))
                    ->filter(static fn (UndergroundCommandDefinition $definition): bool => $projectedFacility === null
                        ? $definition->action === 'build'
                        : $definition->action === 'remove')
                    ->map(
                        function (UndergroundCommandDefinition $definition) use ($undergroundFacilities, $projectedFacility, $nation): array {
                            $unavailableReason = null;
                            try {
                                $undergroundFacilities->assertProjectedCommand($definition, $projectedFacility);
                            } catch (PlayerFacingCommandException $exception) {
                                $unavailableReason = $exception->getMessage();
                            }
                            $shortfall = max(0, $definition->cost_money - $nation->money);
                            $warnings = array_values(array_filter([
                                $unavailableReason,
                                $shortfall > 0 ? '現在の資金では実行できません。' : null,
                            ]));

                            return [
                                'key' => $definition->key,
                                'name' => $definition->name,
                                'command_suffix' => null,
                                'command_suffix_tone' => null,
                                'confirmation_message' => null,
                                'description' => $definition->description,
                                'target_type' => 'underground_slot',
                                'parameters' => (object) [],
                                'quantity_semantics' => CommandQuantitySemantics::UNUSED,
                                'quantity_default' => 1,
                                'quantity_options' => [],
                                'cost_money' => $definition->cost_money,
                                'execution_phase' => 'underground_facility',
                                'initial_facility_capacity' => null,
                                'applicable' => true,
                                'available' => $unavailableReason === null,
                                'shortfall_money' => $shortfall,
                                'unavailable_reason' => $unavailableReason,
                                'execution_preview_status' => $warnings === []
                                    ? 'currently_executable'
                                    : 'currently_unavailable',
                                'execution_warnings' => $warnings,
                            ];
                        },
                    )
                    ->values();
                $quantityContract = $world->rulesetVersion->settings['development_plan_quantity'] ?? null;
                if (! DevelopmentPlanQuantity::matchesContract($quantityContract)) {
                    throw new DomainException('Worldのrulesetはuniversal quantity契約へ移行されていません。');
                }

                return response()->json(['data' => [
                    'commands' => $commands,
                    'quantity_contract' => $quantityContract,
                ]]);
            }
            $cell = null;
            if ($request->has(['target_x', 'target_y'])) {
                $cell = MapCell::query()
                    ->where('map_space_id', $mapSpace->id)
                    ->where('x', $request->integer('target_x'))
                    ->where('y', $request->integer('target_y'))
                    ->with(['terrain', 'facility'])
                    ->first();
            }
            $nationTargetOptions = $this->nationTargets->options($nation);
            $monsterDispatchTargetOptions = $this->nationTargets->monsterDispatchOptions($nation);
            $definitions = CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->each(static fn (CommandDefinition $definition): CommandDefinition => $definition->setRelation(
                    'rulesetVersion',
                    $world->rulesetVersion,
                ));
            $visibleState = $cell === null ? null : MapCellPresenter::visibleState($cell, $nation->id);
            $projectionMemo = new SurfaceCommandProjectionMemo;
            $projected = $cell === null ? null : $service->projectCellStateBeforePosition(
                $cell,
                $queue,
                $position,
                $nation,
                $mapSpace,
                $visibleState,
                $projectionMemo,
            );
            $resultFacilities = FacilityDefinition::query()
                ->whereIn('key', $definitions->pluck('result_facility_key')->filter()->unique()->values())
                ->get()
                ->keyBy('key');
            $definitions = $definitions
                ->map(function (CommandDefinition $definition) use ($cell, $nation, $mapSpace, $service, $capacities, $queue, $position, $nationTargetOptions, $monsterDispatchTargetOptions, $projected, $resultFacilities, $visibleState, $projectionMemo): array {
                    $ownerOverbuildEffect = $projected === null
                        ? null
                        : $service->projectedOwnerOverbuildEffect($definition, $nation, $projected);
                    $unavailableReason = null;
                    $projectedExecutable = false;
                    $currentlyExecutable = false;
                    if ($definition->target_type === 'cell' && $cell !== null) {
                        try {
                            $service->validateTarget($nation, $mapSpace, $definition, $cell, $visibleState);
                            $currentlyExecutable = true;
                        } catch (PlayerFacingCommandException $exception) {
                            $unavailableReason = $exception->getMessage();
                            $projectedExecutable = $definition->key === 'territory_expand'
                                ? $service->projectedTerritoryTargetMatches(
                                    $definition,
                                    $projected,
                                    $cell,
                                    $queue,
                                    $position,
                                    $nation,
                                    $mapSpace,
                                    $projectionMemo,
                                )
                                : $service->projectedTargetMatches(
                                    $definition,
                                    $projected,
                                    $nation,
                                    $mapSpace,
                                    $cell,
                                    $queue,
                                    $position,
                                    $projectionMemo,
                                );
                        }
                        if ($definition->key === 'build_port') {
                            $portProjectedExecutable = $service->projectedTargetMatches(
                                $definition,
                                $projected,
                                $nation,
                                $mapSpace,
                                $cell,
                                $queue,
                                $position,
                                $projectionMemo,
                            );
                            if ($currentlyExecutable && ! $portProjectedExecutable) {
                                $unavailableReason = '予約済みcommand後は港の建設条件を満たしません。';
                            } elseif (! $currentlyExecutable) {
                                $projectedExecutable = $portProjectedExecutable;
                            }
                        }
                    }

                    $resultFacility = $definition->result_facility_key === null
                        ? null
                        : $resultFacilities->get($definition->result_facility_key);
                    $initialCapacity = $resultFacility?->initial_scale === null
                        ? null
                        : $capacities->describe($resultFacility, $capacities->initialScale($resultFacility));
                    $requiresNationTarget = $this->nationTargets->requiresTarget($definition)
                        || $ownerOverbuildEffect === 'monument_flight';
                    $presentedTargetOptions = $ownerOverbuildEffect === 'monument_flight'
                        ? $this->nationTargets->monumentFlightOptions($nation)
                        : ($definition->key === 'monster_dispatch'
                            ? $monsterDispatchTargetOptions
                            : $nationTargetOptions);
                    $parameters = $this->nationTargets->presentParameters($definition, $presentedTargetOptions);
                    if ($ownerOverbuildEffect === 'monument_flight'
                        && is_array($parameters['target_nation_id'] ?? null)) {
                        $parameters['target_nation_id']['required'] = true;
                        $parameters['target_nation_id']['nullable'] = false;
                    }
                    $applicable = ($definition->target_type === 'nation' || $cell !== null)
                        && (! $requiresNationTarget || $presentedTargetOptions !== []);
                    $shortfall = max(0, $definition->cost_money - $nation->money);
                    $warnings = [];
                    if ($projectedExecutable) {
                        $warnings[] = '予約済みcommand後は実行可能です。';
                    } elseif ($unavailableReason !== null) {
                        $warnings[] = $unavailableReason;
                    }
                    if ($shortfall > 0) {
                        $warnings[] = '現在の資金では実行できません。';
                    }

                    return [
                        'key' => $definition->key,
                        'name' => $this->ownerCommandName($definition),
                        'command_suffix' => $ownerOverbuildEffect === 'defense_self_destruct'
                            ? '（自爆）'
                            : null,
                        'command_suffix_tone' => $ownerOverbuildEffect === 'defense_self_destruct' ? 'danger' : null,
                        'confirmation_message' => $ownerOverbuildEffect === 'defense_self_destruct'
                            ? '防衛施設を自爆させます。周囲にも巨大隕石相当の被害が発生します。実行予定へ追加しますか？'
                            : null,
                        'description' => $definition->description,
                        'target_type' => $definition->target_type,
                        'parameters' => $parameters === [] ? (object) [] : $parameters,
                        'quantity_semantics' => $this->quantitySemantics->for($definition),
                        'quantity_default' => $this->quantitySemantics->presentationDefault($definition),
                        'quantity_options' => $this->quantitySemantics->options($definition),
                        'cost_money' => $definition->cost_money,
                        'execution_phase' => $definition->execution_phase,
                        'initial_facility_capacity' => $initialCapacity === null ? null : [
                            ...$initialCapacity,
                            'facility_key' => (string) $definition->result_facility_key,
                            'formatted' => number_format($initialCapacity['capacity_people']).'人規模',
                        ],
                        'applicable' => $applicable,
                        'available' => $applicable,
                        'shortfall_money' => $shortfall,
                        'unavailable_reason' => ! $applicable
                            ? ($requiresNationTarget ? '選択可能な対象島がありません。' : '対象セルを選択してください。')
                            : null,
                        'execution_preview_status' => ! $applicable
                            ? 'target_required'
                            : ($projectedExecutable
                                ? 'executable_after_queue'
                                : ($warnings === [] ? 'currently_executable' : 'currently_unavailable')),
                        'execution_warnings' => $warnings,
                    ];
                });

            $rules = $world->rulesetVersion->settings;
            $quantityContract = $rules['development_plan_quantity'] ?? null;
            if (! DevelopmentPlanQuantity::matchesContract($quantityContract)) {
                throw new DomainException('Worldのrulesetはuniversal quantity契約へ移行されていません。');
            }

            return response()->json(['data' => [
                'commands' => $definitions,
                'quantity_contract' => $quantityContract,
            ]]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function index(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $this->serializeQueue(
                $service->queueFor($request->user(), $nation, $mapSpace),
                $service,
            )]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function store(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        $limit = $this->queueLimit($nation);
        $validated = $request->validate([
            'command_key' => ['required', 'string', 'max:64'],
            'target_x' => ['sometimes', 'nullable', 'integer'],
            'target_y' => ['sometimes', 'nullable', 'integer'],
            'target_layer' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'target_slot_index' => ['sometimes', 'nullable', 'integer', 'between:0,3'],
            'request_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'quantity' => ['sometimes'],
            'parameters' => ['sometimes', 'array'],
            'position' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
            'monster_key' => ['prohibited'],
            'cost_money' => ['prohibited'],
        ]);

        try {
            $quantity = DevelopmentPlanQuantity::normalize(
                $request->input('quantity'),
                $request->exists('quantity'),
            );
            $result = $service->add(
                user: $request->user(),
                nation: $nation,
                mapSpace: $mapSpace,
                commandKey: $validated['command_key'],
                targetX: $validated['target_x'] ?? null,
                targetY: $validated['target_y'] ?? null,
                requestKey: $validated['request_key'],
                expectedVersion: $validated['expected_version'],
                quantity: $quantity,
                parameters: $validated['parameters'] ?? [],
                position: $validated['position'] ?? null,
                quantityProvided: $request->exists('quantity'),
                targetLayer: $validated['target_layer'] ?? null,
                targetSlotIndex: $validated['target_slot_index'] ?? null,
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($this->loadQueue($result['queue']), $service),
                'item_id' => $result['item']->id,
                'duplicate' => $result['duplicate'],
                'message' => $result['duplicate']
                    ? '同じ開発計画は登録済みです。'
                    : '開発計画に登録されました。実行時に資金・資源・地形・施設・所有権・怪獣占有を再確認します。',
            ]], $result['duplicate'] ? 200 : 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function reorder(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        $limit = $this->queueLimit($nation);
        $validated = $request->validate([
            'ordered_ids' => ['required_without:placements', 'array', "max:{$limit}"],
            'ordered_ids.*' => ['integer', 'distinct'],
            'placements' => ['required_without:ordered_ids', 'array', "max:{$limit}"],
            'placements.*.id' => ['required_with:placements', 'integer', 'distinct'],
            'placements.*.position' => ['required_with:placements', 'integer', 'min:1', "max:{$limit}", 'distinct'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $service->queueFor($request->user(), $nation, $mapSpace, mutationPreflight: true);
            $queue = isset($validated['placements'])
                ? $service->reposition($request->user(), $nation, $validated['placements'], $validated['expected_version'])
                : $service->reorder($request->user(), $nation, $validated['ordered_ids'], $validated['expected_version']);

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue), $service)]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function bulk(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        $limit = $this->queueLimit($nation);
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:clear_all,level_all,reclaim_clear_all,reclaim_level_all'],
            'position' => ['required', 'integer', 'min:1', "max:{$limit}"],
            'request_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $service->bulkInsert(
                $request->user(),
                $nation,
                $mapSpace,
                $validated['action'],
                $validated['position'],
                $validated['request_key'],
                $validated['expected_version'],
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($this->loadQueue($result['queue']), $service),
                'inserted_count' => $result['inserted_count'],
                'truncated_count' => $result['truncated_count'],
                'candidate_count' => $result['candidate_count'],
                'duplicate' => $result['duplicate'],
            ]]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function cancelFrom(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        $limit = $this->queueLimit($nation);
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:1', "max:{$limit}"],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $service->cancelFromPosition(
                $request->user(),
                $nation,
                $mapSpace,
                $validated['position'],
                $validated['expected_version'],
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($this->loadQueue($result['queue']), $service),
                'deleted_count' => $result['deleted_count'],
            ]]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function cancel(Request $request, Nation $nation, MapSpace $mapSpace, NationCommandQueueItem $item, CommandQueueService $service): JsonResponse
    {
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        try {
            $service->queueFor($request->user(), $nation, $mapSpace, mutationPreflight: true);
            $queue = $service->cancel($request->user(), $nation, $item, $validated['expected_version']);

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue), $service)]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function update(
        Request $request,
        Nation $nation,
        MapSpace $mapSpace,
        NationCommandQueueItem $item,
        CommandQueueService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'quantity' => ['required'],
            'parameters' => ['prohibited'],
        ]);

        try {
            $service->queueFor($request->user(), $nation, $mapSpace, mutationPreflight: true);
            $queue = $service->updateQuantity(
                $request->user(),
                $nation,
                $item,
                DevelopmentPlanQuantity::normalize($request->input('quantity'), true),
                $validated['expected_version'],
            );

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue), $service)]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function serializeQueue(NationCommandQueue $queue, CommandQueueService $service): array
    {
        $nation = $queue->nation()->firstOrFail();
        $mapSpace = MapSpace::query()->findOrFail($queue->map_space_id);
        $projectionMemo = new SurfaceCommandProjectionMemo;
        $items = $this->legacyOrder->project($queue->items)
            ->map(function (NationCommandQueueItem $item) use ($queue, $nation, $service, $mapSpace, $projectionMemo): array {
                $definition = $service->definitionForItem($item);
                $cell = $item->target_context === 'surface_cell'
                    ? MapCell::query()->where('map_space_id', $queue->map_space_id)
                        ->where('x', $item->target_x)->where('y', $item->target_y)
                        ->with(['terrain', 'facility'])->first()
                    : null;
                $projected = $cell === null ? null : $service->projectCellStateBeforePosition(
                    $cell,
                    $queue,
                    (int) $item->queue_position,
                    $nation,
                    $mapSpace,
                    projectionMemo: $projectionMemo,
                );
                $effect = $projected === null || ! $definition instanceof CommandDefinition
                    ? null
                    : $service->projectedOwnerOverbuildEffect($definition, $nation, $projected);
                $targetNationId = $item->parameters['target_nation_id'] ?? null;
                $targetName = is_int($targetNationId) ? Nation::query()->whereKey($targetNationId)->value('name') : null;

                return [
                    'id' => $item->id,
                    'command_key' => $definition->key,
                    'command_name' => $this->ownerCommandName($definition),
                    'command_suffix' => $effect === 'defense_self_destruct'
                        ? '（自爆）'
                        : ($effect === 'monument_flight' && is_string($targetName) ? "（{$targetName}）" : null),
                    'command_suffix_tone' => $effect === 'defense_self_destruct' ? 'danger' : null,
                    'queue_position' => $item->queue_position,
                    'target_x' => $item->target_x,
                    'target_y' => $item->target_y,
                    'target_context' => $item->target_context,
                    'target_layer' => $item->target_layer,
                    'target_slot_index' => $item->target_slot_index,
                    'quantity' => $item->quantity,
                    'quantity_semantics' => $definition instanceof UndergroundCommandDefinition
                        ? CommandQuantitySemantics::UNUSED
                        : $this->quantitySemantics->for($definition),
                    'quantity_label' => $definition instanceof UndergroundCommandDefinition
                        ? null
                        : $this->quantitySemantics->label($definition, $item->quantity),
                    'effective_cost_money' => $this->effectiveCostMoney($definition, $item),
                    'parameters' => $item->parameters === [] ? (object) [] : $item->parameters,
                    'status' => $item->status,
                    'queued_at' => $item->queued_at?->toIso8601String(),
                ];
            })->values();
        $byPosition = $items->keyBy('queue_position');
        $limit = $this->queueLimit($nation);
        $plan = collect(range(1, $limit))->map(static function (int $position) use ($byPosition): array {
            $item = $byPosition->get($position);
            if ($item === null) {
                return [
                    'position' => $position,
                    'kind' => 'automatic_finance',
                    'editable' => false,
                    'command_name' => '資金繰り',
                    'quantity' => null,
                ];
            }

            return [
                ...$item,
                'position' => $position,
                'kind' => 'explicit',
                'editable' => true,
            ];
        });

        return [
            'version' => $queue->version,
            'limit' => $limit,
            'explicit_count' => $items->count(),
            'items' => $items,
            'plan' => $plan,
        ];
    }

    private function effectiveCostMoney(
        CommandDefinition|UndergroundCommandDefinition $definition,
        NationCommandQueueItem $item,
    ): int {
        if (! $definition instanceof CommandDefinition) {
            return $definition->cost_money;
        }

        return $this->quantitySemantics->effectiveCostMoney($definition, $item->quantity);
    }

    private function loadQueue(NationCommandQueue $queue): NationCommandQueue
    {
        return $queue->load([
            'items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'),
            'items.definition',
        ]);
    }

    private function ownerCommandName(CommandDefinition|UndergroundCommandDefinition $definition): string
    {
        return $definition->key === 'build_decoy' ? 'ハリボテ建築' : $definition->name;
    }

    private function queueLimit(Nation $nation): int
    {
        $settings = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;

        return CommandQueueLimit::fromRulesetSettings($settings);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $playerFacing = $exception instanceof PlayerFacingCommandException;
        $payload = ['message' => $playerFacing || $exception instanceof CommandRequestConflictException
            ? $exception->getMessage()
            : '入力内容を確認してください。'];
        if ($playerFacing) {
            $payload['code'] = 'command_rejected';
            $payload['errors'] = ['command' => [$exception->getMessage()]];
        }
        if ($exception instanceof ResetRequiredException) {
            $payload['code'] = ResetRequiredException::ERROR_CODE;
        }
        if ($exception instanceof CommandRequestConflictException) {
            $payload['code'] = CommandRequestConflictException::ERROR_CODE;
        }

        return response()->json(
            $payload,
            $exception instanceof OptimisticLockException
                || $exception instanceof ResetRequiredException
                || $exception instanceof CommandRequestConflictException ? 409 : 422,
        );
    }
}
