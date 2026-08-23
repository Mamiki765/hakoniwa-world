<?php

namespace App\Http\Controllers\Api;

use App\Application\ManualNationDormancyService;
use App\Domain\Nation\NationDormancyConflictException;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DormantNationRequest;
use App\Http\Resources\NationResource;
use App\Models\Nation;
use Illuminate\Http\JsonResponse;

final class NationDormancyController extends Controller
{
    public function store(
        DormantNationRequest $request,
        Nation $nation,
        ManualNationDormancyService $dormancy,
    ): JsonResponse {
        try {
            $updated = $dormancy->enter($request->user(), $nation, $request->integer('days'));
        } catch (NationDormancyConflictException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        } catch (ResetRequiredException $exception) {
            return response()->json([
                'code' => ResetRequiredException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 409);
        }

        $updated->load(['capital', 'resourceBalances.definition']);

        return (new NationResource($updated))->response();
    }
}
