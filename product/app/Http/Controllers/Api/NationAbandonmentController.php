<?php

namespace App\Http\Controllers\Api;

use App\Application\NationAbandonmentService;
use App\Domain\Nation\NationAbandonmentConfirmationException;
use App\Domain\Nation\NationAbandonmentConflictException;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AbandonNationRequest;
use App\Models\Nation;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class NationAbandonmentController extends Controller
{
    public function store(
        AbandonNationRequest $request,
        Nation $nation,
        NationAbandonmentService $abandonment,
    ): JsonResponse {
        try {
            $result = $abandonment->abandon(
                $request->user(),
                $nation,
                $request->string('confirmation_name')->value(),
            );
        } catch (NationAbandonmentConfirmationException $exception) {
            throw ValidationException::withMessages([
                'confirmation_name' => $exception->getMessage(),
            ]);
        } catch (NationAbandonmentConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (ResetRequiredException $exception) {
            return response()->json([
                'code' => ResetRequiredException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $result]);
    }
}
