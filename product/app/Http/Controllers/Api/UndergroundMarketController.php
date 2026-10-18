<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\UndergroundMarketService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UndergroundMarketController extends Controller
{
    public function index(Request $request, UndergroundMarketService $service): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', Rule::in(['weapon', 'armor', 'accessory', 'resonance'])],
            'weapon_style' => ['sometimes', 'string', 'max:64'],
            'il_min' => ['sometimes', 'integer', 'min:0'], 'quality_min' => ['sometimes', 'integer', 'between:0,100'],
            'sort' => ['sometimes', Rule::in(['newest', 'price', 'item_level'])],
            'purchasable_only' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->respond(fn (): array => $service->browse($request->user(), $filters));
    }

    public function listable(Request $request, UndergroundMarketService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->listable($request->user()));
    }

    public function mine(Request $request, UndergroundMarketService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->mine($request->user()));
    }

    public function store(Request $request, UndergroundMarketService $service): JsonResponse
    {
        $request->validate(['request_id' => ['required', 'uuid'], 'equipment_id' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);

        return $this->respond(fn (): array => $service->create($request->user(), $request->string('request_id')->value(), $request->integer('equipment_id'), $request->integer('price')));
    }

    public function purchase(Request $request, int $listingId, UndergroundMarketService $service): JsonResponse
    {
        $request->validate(['request_id' => ['required', 'uuid'], 'expected_price' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);

        return $this->respond(fn (): array => $service->purchase($request->user(), $request->string('request_id')->value(), $listingId, $request->integer('expected_price')));
    }

    public function cancel(Request $request, int $listingId, UndergroundMarketService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->cancel($request->user(), $listingId));
    }

    public function claim(Request $request, UndergroundMarketService $service): JsonResponse
    {
        $request->validate(['request_id' => ['required', 'uuid'], 'listing_ids' => ['nullable', 'array', 'max:1000'], 'listing_ids.*' => ['integer', 'min:1', 'distinct']]);

        return $this->respond(fn (): array => $service->claim($request->user(), $request->string('request_id')->value(),
            $request->input('listing_ids') === null ? null : array_map(intval(...), $request->array('listing_ids'))));
    }

    public function seen(Request $request, UndergroundMarketService $service): JsonResponse
    {
        $request->validate(['listing_ids' => ['required', 'array', 'max:1000'], 'listing_ids.*' => ['integer', 'min:1', 'distinct']]);

        return $this->respond(fn (): array => $service->seen($request->user(), array_map(intval(...), $request->array('listing_ids'))));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()]);
        } catch (UndergroundRuntimeException $exception) {
            $conflict = in_array($exception->errorCode, ['request_conflict', 'listing_unavailable', 'price_changed'], true);

            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $conflict ? 409 : 422);
        }
    }
}
