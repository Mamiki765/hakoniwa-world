<?php

namespace App\Http\Controllers\Api;

use App\Application\SurfaceShipCourseService;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Ruleset\ResetRequiredException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Models\Ship;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SurfaceShipController extends Controller
{
    public function updateHeading(
        Request $request,
        Nation $nation,
        Ship $ship,
        SurfaceShipCourseService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'heading' => ['present', 'nullable', 'integer', 'between:0,5'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $service->update(
                $request->user(),
                $nation,
                $ship,
                $validated['heading'],
                $validated['expected_version'],
            );
        } catch (OptimisticLockException|ResetRequiredException|TurnAlreadyRunningException|UnresolvedNextTurnRunException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...($exception instanceof ResetRequiredException ? ['code' => ResetRequiredException::ERROR_CODE] : []),
            ], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => (int) $updated->id,
            'heading' => $updated->heading,
            'version' => (int) $updated->version,
        ]]);
    }
}
