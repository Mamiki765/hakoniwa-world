<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryWarehouseService;
use App\Domain\Secretary\SecretaryInventoryException;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecretaryWarehouseController extends Controller
{
    public function expand(Request $request, SecretaryWarehouseService $warehouse): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'expected_expansions' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $result = $warehouse->expand($request->user(), $data['request_id'], (int) $data['expected_expansions']);
        } catch (SecretaryInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status);
        } catch (SecretaryNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => SecretaryNotFoundException::ERROR_CODE], 404);
        }

        return response()->json(['data' => $result]);
    }
}
