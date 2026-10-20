<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryCollectionService;
use App\Domain\Secretary\SecretaryInventoryException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecretaryCollectionController extends Controller
{
    public function index(Request $request, SecretaryCollectionService $collection): JsonResponse
    {
        return response()->json(['data' => $collection->index($request->user())]);
    }

    public function claim(Request $request, SecretaryCollectionService $collection): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:monster,item,bonus'],
            'key' => ['required', 'string', 'max:64'],
        ]);
        try {
            $result = $collection->claim($request->user(), $data['kind'], $data['key']);
        } catch (SecretaryInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status);
        }

        return response()->json(['data' => $result]);
    }

    public function claimAll(Request $request, SecretaryCollectionService $collection): JsonResponse
    {
        return response()->json(['data' => $collection->claimAll($request->user())]);
    }
}
