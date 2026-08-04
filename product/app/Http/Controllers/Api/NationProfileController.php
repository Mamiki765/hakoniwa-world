<?php

namespace App\Http\Controllers\Api;

use App\Application\NationProfileService;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNationProfileRequest;
use App\Http\Resources\NationResource;
use App\Models\Nation;
use DomainException;
use Illuminate\Http\JsonResponse;

final class NationProfileController extends Controller
{
    public function update(
        UpdateNationProfileRequest $request,
        Nation $nation,
        NationProfileService $profiles,
    ): NationResource|JsonResponse {
        try {
            $nation = $profiles->update($request->user(), $nation, $request->validated());
        } catch (ResetRequiredException $exception) {
            return response()->json([
                'code' => ResetRequiredException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return new NationResource($nation->load(['capital', 'resourceBalances.definition']));
    }
}
