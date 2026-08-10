<?php

namespace App\Http\Controllers\Api;

use App\Application\MessageBoardService;
use App\Domain\MessageBoard\MessageBoardCooldownException;
use App\Domain\MessageBoard\MessageBoardValidationException;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Models\Nation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MessageBoardController extends Controller
{
    public function show(Request $request, Nation $nation, MessageBoardService $service): JsonResponse
    {
        return response()->json(['data' => $service->timeline($nation, $request->user())]);
    }

    public function storePublic(Request $request, Nation $nation, MessageBoardService $service): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string']]);

        try {
            $timeline = $service->postPublic($request->user(), $nation, $validated['body']);
        } catch (MessageBoardCooldownException $exception) {
            return $this->cooldown($exception);
        } catch (ResetRequiredException $exception) {
            return $this->resetRequired($exception);
        } catch (MessageBoardValidationException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return response()->json(['data' => $timeline], 201);
    }

    public function storeSecret(Request $request, Nation $nation, MessageBoardService $service): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string']]);

        try {
            $timeline = $service->postSecret($request->user(), $nation, $validated['body']);
        } catch (MessageBoardCooldownException $exception) {
            return $this->cooldown($exception);
        } catch (ResetRequiredException $exception) {
            return $this->resetRequired($exception);
        } catch (MessageBoardValidationException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return response()->json(['data' => $timeline], 201);
    }

    private function cooldown(MessageBoardCooldownException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'retry_after_seconds' => $exception->retryAfterSeconds,
            'retry_at' => $exception->retryAt->toIso8601String(),
        ], 429)->header('Retry-After', (string) $exception->retryAfterSeconds);
    }

    private function resetRequired(ResetRequiredException $exception): JsonResponse
    {
        return response()->json([
            'code' => ResetRequiredException::ERROR_CODE,
            'message' => $exception->getMessage(),
        ], 409);
    }
}
