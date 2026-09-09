<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\GuideConversationService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuideConversationController extends Controller
{
    public function start(Request $request, GuideConversationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->start($request->user()));
    }

    public function reply(Request $request, GuideConversationService $service): JsonResponse
    {
        $validated = $request->validate([
            'topic_id' => ['required', 'integer', 'min:1'],
            'position' => ['required', 'integer', 'between:1,3'],
        ]);

        return $this->respond(fn (): array => $service->reply(
            $request->user(),
            (int) $validated['topic_id'],
            (int) $validated['position'],
        ));
    }

    public function punch(Request $request, GuideConversationService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->punch($request->user()));
    }

    /** @param callable(): array<mixed> $operation */
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
