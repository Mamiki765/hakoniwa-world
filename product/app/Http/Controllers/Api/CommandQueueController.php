<?php

namespace App\Http\Controllers\Api;

use App\Application\CommandQueueService;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Facility\FacilityCapacityService;
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
    ): JsonResponse
    {
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
                        'available' => $cell !== null && $unavailableReason === null,
                        'unavailable_reason' => $cell === null ? '対象セルを選択してください。' : $unavailableReason,
                    ];
                });

            return response()->json(['data' => $definitions]);
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
        $validated = $request->validate([
            'command_key' => ['required', 'string', 'max:64'],
            'target_x' => ['required', 'integer'],
            'target_y' => ['required', 'integer'],
            'request_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'parameters' => ['sometimes', 'array'],
        ]);

        try {
            $result = $service->add(
                $request->user(), $nation, $mapSpace,
                $validated['command_key'], $validated['target_x'], $validated['target_y'],
                $validated['request_key'], $validated['expected_version'], $validated['parameters'] ?? [],
            );

            return response()->json(['data' => [
                'queue' => $this->serializeQueue($result['queue']->load(['items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'), 'items.definition'])),
                'item_id' => $result['item']->id,
                'message' => 'commandはqueueへ登録されただけで、まだ実行されていません。',
            ]], 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function reorder(Request $request, Nation $nation, MapSpace $mapSpace, CommandQueueService $service): JsonResponse
    {
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'max:20'],
            'ordered_ids.*' => ['integer', 'distinct'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $service->queueFor($request->user(), $nation, $mapSpace);
            $queue = $service->reorder($request->user(), $nation, $validated['ordered_ids'], $validated['expected_version']);

            return response()->json(['data' => $this->serializeQueue($queue->load(['items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'), 'items.definition']))]);
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

            return response()->json(['data' => $this->serializeQueue($queue->load(['items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'), 'items.definition']))]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function serializeQueue(NationCommandQueue $queue): array
    {
        return [
            'version' => $queue->version,
            'limit' => (int) config('hakoniwa.ruleset.command_queue_limit', 20),
            'items' => $queue->items->map(static fn (NationCommandQueueItem $item): array => [
                'id' => $item->id,
                'command_key' => $item->definition->key,
                'command_name' => $item->definition->name,
                'queue_position' => $item->queue_position,
                'target_x' => $item->target_x,
                'target_y' => $item->target_y,
                'parameters' => $item->parameters,
                'status' => $item->status,
                'queued_at' => $item->queued_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json(
            ['message' => $exception->getMessage()],
            $exception instanceof OptimisticLockException ? 409 : 422,
        );
    }
}
