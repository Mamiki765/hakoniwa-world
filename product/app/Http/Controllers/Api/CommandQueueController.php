<?php

namespace App\Http\Controllers\Api;

use App\Application\CommandQuantitySemantics;
use App\Application\CommandQueueService;
use App\Application\LegacyCommandQueueOrder;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Command\SettlementOverbuildPolicy;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommandQueueController extends Controller
{
    public function __construct(
        private readonly LegacyCommandQueueOrder $legacyOrder,
        private readonly CommandQuantitySemantics $quantitySemantics,
    ) {}

    public function definitions(
        Request $request,
        Nation $nation,
        MapSpace $mapSpace,
        CommandQueueService $service,
        FacilityCapacityService $capacities,
    ): JsonResponse {
        try {
            $queue = $service->queueFor($request->user(), $nation, $mapSpace);
            $position = max(1, min(
                $this->queueLimit($nation),
                $request->integer('position', 1),
            ));
            $cell = null;
            if ($request->has(['target_x', 'target_y'])) {
                $cell = MapCell::query()
                    ->where('map_space_id', $mapSpace->id)
                    ->where('x', $request->integer('target_x'))
                    ->where('y', $request->integer('target_y'))
                    ->with(['terrain', 'facility'])
                    ->first();
            }
            $definitions = CommandDefinition::query()
                ->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function (CommandDefinition $definition) use ($cell, $nation, $mapSpace, $service, $capacities, $queue, $position): array {
                    $unavailableReason = null;
                    $projectedExecutable = false;
                    if ($definition->target_type === 'cell' && $cell !== null) {
                        try {
                            $service->validateTarget($nation, $mapSpace, $definition, $cell);
                        } catch (DomainException $exception) {
                            $unavailableReason = $exception->getMessage();
                            $projected = $this->projectedCellState($cell, $queue, $position, $nation);
                            $projectedExecutable = $this->matchesProjectedTarget($definition, $projected, $nation);
                        }
                    }

                    $resultFacility = $definition->result_facility_key === null
                        ? null
                        : FacilityDefinition::query()->where('key', $definition->result_facility_key)->first();
                    $initialCapacity = $resultFacility?->initial_scale === null
                        ? null
                        : $capacities->describe($resultFacility, $capacities->initialScale($resultFacility));
                    $applicable = $definition->target_type === 'nation' || $cell !== null;
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
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'target_type' => $definition->target_type,
                        'parameters' => $definition->metadata['parameters'] ?? (object) [],
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
                        'unavailable_reason' => ! $applicable ? '対象セルを選択してください。' : null,
                        'execution_preview_status' => ! $applicable
                            ? 'target_required'
                            : ($projectedExecutable
                                ? 'executable_after_queue'
                                : ($warnings === [] ? 'currently_executable' : 'currently_unavailable')),
                        'execution_warnings' => $warnings,
                    ];
                });

            $rules = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
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
            return response()->json(['data' => $this->serializeQueue($service->queueFor($request->user(), $nation, $mapSpace))]);
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
            'request_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'quantity' => ['sometimes'],
            'parameters' => ['sometimes', 'array'],
            'position' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
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
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($this->loadQueue($result['queue'])),
                'item_id' => $result['item']->id,
                'message' => '開発計画に登録されました。実行時に資金・資源・地形・施設・所有権・怪獣占有を再確認します。',
            ]], 201);
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

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue))]);
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

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue))]);
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

            return response()->json(['data' => $this->serializeQueue($this->loadQueue($queue))]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function serializeQueue(NationCommandQueue $queue): array
    {
        $items = $this->legacyOrder->project($queue->items)
            ->map(fn (NationCommandQueueItem $item): array => [
                'id' => $item->id,
                'command_key' => $item->definition->key,
                'command_name' => $item->definition->name,
                'queue_position' => $item->queue_position,
                'target_x' => $item->target_x,
                'target_y' => $item->target_y,
                'quantity' => $item->quantity,
                'quantity_semantics' => $this->quantitySemantics->for($item->definition),
                'quantity_label' => $this->quantitySemantics->label($item->definition, $item->quantity),
                'parameters' => $item->parameters === [] ? (object) [] : $item->parameters,
                'status' => $item->status,
                'queued_at' => $item->queued_at?->toIso8601String(),
            ])->values();
        $byPosition = $items->keyBy('queue_position');
        $limit = $this->queueLimit($queue->nation()->firstOrFail());
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

    private function loadQueue(NationCommandQueue $queue): NationCommandQueue
    {
        return $queue->load([
            'items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'),
            'items.definition',
        ]);
    }

    private function queueLimit(Nation $nation): int
    {
        $settings = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;

        return CommandQueueLimit::fromRulesetSettings($settings);
    }

    /**
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}
     */
    private function projectedCellState(
        MapCell $cell,
        NationCommandQueue $queue,
        int $beforePosition,
        Nation $nation,
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
            if (! $this->matchesProjectedTarget($definition, $state, $nation)) {
                continue;
            }
            $state = $this->applyProjectedResult($definition, $state, $nation);
        }

        return $state;
    }

    /**
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     * @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}
     */
    private function applyProjectedResult(CommandDefinition $definition, array $state, Nation $nation): array
    {
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
     * @param  array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null}  $state
     */
    private function matchesProjectedTarget(CommandDefinition $definition, array $state, Nation $nation): bool
    {
        if (! in_array($state['terrain_key'], $definition->target_terrain_keys, true)) {
            return false;
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $state['facility_key'])) {
            return false;
        }
        if ($definition->requires_empty_facility && $state['facility_key'] !== null
            && ! SettlementOverbuildPolicy::allows($definition->key, $state['facility_key'])) {
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

    private function domainError(DomainException $exception): JsonResponse
    {
        $payload = ['message' => $exception->getMessage()];
        if ($exception instanceof ResetRequiredException) {
            $payload['code'] = ResetRequiredException::ERROR_CODE;
        }

        return response()->json(
            $payload,
            $exception instanceof OptimisticLockException || $exception instanceof ResetRequiredException ? 409 : 422,
        );
    }
}
