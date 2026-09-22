<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\UndergroundRequestAdmission;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class UndergroundRequestAdmissionController extends Controller
{
    public function __invoke(Request $request, UndergroundRequestAdmission $admission): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:POST,PUT,PATCH,DELETE'],
            'path' => ['required', 'string', 'max:250'],
            'request_id' => ['prohibited'], 'token' => ['prohibited'],
        ]);
        $path = $data['path'];
        if (! is_string($path) || ! str_starts_with($path, '/api/v1/me/underground/')
            || str_contains($path, '?') || ! UndergroundRequestAdmission::required($data['method'], $path)) {
            return response()->json(['message' => '受付対象の操作を確認してください。'], 422);
        }
        try {
            Route::getRoutes()->match(Request::create($path, $data['method']));
        } catch (HttpExceptionInterface) {
            return response()->json(['message' => '受付対象の操作を確認してください。'], 422);
        }
        try {
            return response()->json(['data' => $admission->issue($request->user(), $data['method'], $path)]);
        } catch (UndergroundRuntimeException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        }
    }
}
