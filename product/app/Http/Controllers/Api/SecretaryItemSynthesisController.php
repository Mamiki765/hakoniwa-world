<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryItemSynthesisService;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecretaryItemSynthesisController extends Controller
{
    public function index(Request $request, SecretaryItemSynthesisService $synthesis): JsonResponse
    {
        return response()->json(['data' => ['recipes' => $synthesis->available($request->user())]]);
    }

    public function store(Request $request, SecretaryItemSynthesisService $synthesis): JsonResponse
    {
        $validated = $request->validate([
            'recipe_key' => ['required', 'string', 'max:64'],
            'ingredient_ids' => ['required', 'array', 'size:3'],
            'ingredient_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'request_key' => ['required', 'uuid'],
        ]);
        try {
            $result = $synthesis->synthesize($request->user(), (string) $validated['recipe_key'],
                array_map(intval(...), array_values($validated['ingredient_ids'])), (string) $validated['request_key']);
        } catch (TurnAlreadyRunningException) {
            return response()->json([
                'code' => 'secretary_item_synthesis_busy',
                'message' => '現在ほかの処理が進行中です。同じ合成の結果をもう一度確認してください。',
            ], 409);
        } catch (DomainException $exception) {
            return response()->json(['code' => 'secretary_item_synthesis_rejected', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['item' => $result]]);
    }
}
