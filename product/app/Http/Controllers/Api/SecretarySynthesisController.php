<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretarySynthesisService;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryInventoryException;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecretarySynthesisController extends Controller
{
    public function index(Request $request, SecretarySynthesisService $synthesis): JsonResponse
    {
        try {
            $result = $synthesis->index($request->user());
        } catch (SecretaryNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => SecretaryNotFoundException::ERROR_CODE], 404);
        }

        return response()->json(['data' => $result]);
    }

    public function store(Request $request, SecretarySynthesisService $synthesis): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'recipe_key' => ['required', 'string', 'max:128'],
            'material_ids' => ['required', 'array', 'min:2', 'max:250'],
            'material_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'base_id' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            $result = $synthesis->synthesize(
                $request->user(), $data['request_id'], $data['recipe_key'],
                array_map('intval', $data['material_ids']), isset($data['base_id']) ? (int) $data['base_id'] : null,
            );
        } catch (SecretaryInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status);
        } catch (SecretaryEquipmentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], 409);
        } catch (SecretaryNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => SecretaryNotFoundException::ERROR_CODE], 404);
        }

        return response()->json(['data' => $result]);
    }
}
