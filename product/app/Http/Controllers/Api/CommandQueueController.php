<?php

namespace App\Http\Controllers\Api;

use App\Application\CommandQueueService;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
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
    public function definitions(
        Request $request,
        Nation $nation,
        MapSpace $mapSpace,
        CommandQueueService $service,
        FacilityCapacityService $capacities,
    ): JsonResponse {
        try {
            $service->queueFor($request->user(), $nation, $mapSpace);
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
                ->map(function (CommandDefinition $definition) use ($cell, $nation, $mapSpace, $service, $capacities): array {
                    $unavailableReason = null;
                    if ($cell !== null) {
                        try {
                            $service->validateTarget($nation, $mapSpace, $definition, $cell);
                        } catch (DomainException $exception) {
                            $unavailableReason = $exception->getMessage();
                        }
                    }

                    $resultFacility = $definition->result_facility_key === null
                        ? null
                        : FacilityDefinition::query()->where('key', $definition->result_facility_key)->first();
                    $initialCapacity = $resultFacility?->initial_scale === null
                        ? null
                        : $capacities->describe($resultFacility, $capacities->initialScale($resultFacility));
                    $applicable = $cell !== null && $unavailableReason === null;
                    $shortfall = max(0, $definition->cost_money - $nation->money);
                    if ($applicable && $shortfall > 0) {
                        $unavailableReason = '資金が'.number_format($shortfall).'億円不足しています。';
                    }

                    return [
                        'key' => $definition->key,
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'cost_money' => $definition->cost_money,
                        'execution_phase' => $definition->execution_phase,
                        'initial_facility_capacity' => $initialCapacity === null ? null : [
                            ...$initialCapacity,
                            'facility_key' => (string) $definition->result_facility_key,
                            'formatted' => number_format($initialCapacity['capacity_people']).'人規模',
                        ],
                        'applicable' => $applicable,
                        'available' => $applicable && $shortfall === 0,
                        'shortfall_money' => $shortfall,
                        'unavailable_reason' => $cell === null ? '対象セルを選択してください。' : $unavailableReason,
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
            'target_x' => ['required', 'integer'],
            'target_y' => ['required', 'integer'],
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
                targetX: $validated['target_x'],
                targetY: $validated['target_y'],
                requestKey: $validated['request_key'],
                expectedVersion: $validated['expected_version'],
                quantity: $quantity,
                parameters: $validated['parameters'] ?? [],
                position: $validated['position'] ?? null,
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($this->loadQueue($result['queue'])),
                'item_id' => $result['item']->id,
                'message' => '開発計画に登録されました。まだ実行されていません。実行はターン更新時に行われます。登録時点では資金・地形・施設は変化しません。',
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
            $service->queueFor($request->user(), $nation, $mapSpace);
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
            $service->queueFor($request->user(), $nation, $mapSpace);
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
            $service->queueFor($request->user(), $nation, $mapSpace);
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
        $items = $queue->items->map(static fn (NationCommandQueueItem $item): array => [
            'id' => $item->id,
            'command_key' => $item->definition->key,
            'command_name' => $item->definition->name,
            'queue_position' => $item->queue_position,
            'target_x' => $item->target_x,
            'target_y' => $item->target_y,
            'quantity' => $item->quantity,
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
