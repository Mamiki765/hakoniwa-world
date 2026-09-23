<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\UndergroundEquipmentService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmUndergroundBulkSellRequest;
use App\Http\Requests\EquipUndergroundEquipmentRequest;
use App\Http\Requests\PreviewUndergroundBulkSellRequest;
use App\Http\Requests\PurchaseUndergroundEquipmentRequest;
use App\Http\Requests\SellUndergroundEquipmentRequest;
use App\Http\Requests\UndergroundIntroMutationRequest;
use App\Http\Requests\UnequipUndergroundEquipmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UndergroundEquipmentController extends Controller
{
    public function shop(Request $request, UndergroundEquipmentService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->shop($request->user()));
    }

    public function polishing(Request $request, UndergroundEquipmentService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->polishing($request->user()));
    }

    public function polish(UndergroundIntroMutationRequest $request, UndergroundEquipmentService $service): JsonResponse
    {
        $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'level' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn (): array => $service->polish(
            $request->user(), $request->string('request_id')->value(), $request->integer('item_id'),
            $request->integer('level'), $request->integer('price'),
        ));
    }

    public function vault(Request $request, UndergroundEquipmentService $service): JsonResponse
    {
        $request->validate([
            'sort' => ['sometimes', 'string', Rule::in(UndergroundEquipmentService::VAULT_SORT_KEYS)],
            'inventory' => ['sometimes', 'string', Rule::in(['equipment', 'resonance'])],
        ]);

        return $this->respond(fn (): array => $service->vault(
            $request->user(),
            $request->integer('page', 1),
            $request->string('sort', 'newest')->value(),
            $request->string('inventory', 'equipment')->value(),
        ));
    }

    public function purchase(
        PurchaseUndergroundEquipmentRequest $request,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->purchase(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('definition_key')->value(),
        ));
    }

    public function sell(
        SellUndergroundEquipmentRequest $request,
        int $itemId,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->sell(
            $request->user(),
            $request->string('request_id')->value(),
            $itemId,
        ));
    }

    public function previewBulkSell(
        PreviewUndergroundBulkSellRequest $request,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->bulkSellPreview(
            $request->user(),
            $request->filled('item_level_max') ? $request->integer('item_level_max') : null,
            $request->array('rarities'),
            $request->array('categories'),
            $request->array('weapon_styles'),
        ));
    }

    public function bulkSell(
        ConfirmUndergroundBulkSellRequest $request,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->bulkSell(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('catalog_identity')->value(),
            $request->array('items'),
        ));
    }

    public function equip(
        EquipUndergroundEquipmentRequest $request,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->equip(
            $request->user(),
            $request->string('request_id')->value(),
            $request->integer('item_id'),
            $request->filled('target_slot')
                ? $request->string('target_slot')->value()
                : null,
        ));
    }

    public function unequip(
        UnequipUndergroundEquipmentRequest $request,
        string $slot,
        UndergroundEquipmentService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->unequip(
            $request->user(),
            $request->string('request_id')->value(),
            $slot,
        ));
    }

    /** @param callable():array<string, mixed> $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()]);
        } catch (UndergroundRuntimeException $exception) {
            $status = $exception->errorCode === 'underground_secretary_missing' ? 404 : 409;

            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $status);
        }
    }
}
