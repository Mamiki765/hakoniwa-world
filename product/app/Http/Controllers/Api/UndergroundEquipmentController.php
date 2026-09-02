<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\UndergroundEquipmentService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EquipUndergroundEquipmentRequest;
use App\Http\Requests\PurchaseUndergroundEquipmentRequest;
use App\Http\Requests\SellUndergroundEquipmentRequest;
use App\Http\Requests\UnequipUndergroundEquipmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UndergroundEquipmentController extends Controller
{
    public function shop(Request $request, UndergroundEquipmentService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->shop($request->user()));
    }

    public function vault(Request $request, UndergroundEquipmentService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->vault(
            $request->user(),
            $request->integer('page', 1),
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
