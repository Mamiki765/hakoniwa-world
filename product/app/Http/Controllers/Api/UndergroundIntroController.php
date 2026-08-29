<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceUndergroundIntroRequest;
use App\Http\Requests\NameUndergroundShopkeeperRequest;
use App\Http\Requests\UndergroundIntroMutationRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UndergroundIntroController extends Controller
{
    public function show(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->state($request->user()));
    }

    public function enter(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->enter(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function advance(
        AdvanceUndergroundIntroRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->advance(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('action')->value(),
        ));
    }

    public function tutorial(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->tutorial(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function nameShopkeeper(
        NameUndergroundShopkeeperRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        try {
            return $this->respond(fn (): array => $service->nameShopkeeper(
                $request->user(),
                $request->string('request_id')->value(),
                $request->string('name')->value(),
            ));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }

    public function scriptedLoss(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->scriptedLoss(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function main(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->main($request->user()));
    }

    public function battles(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->battles($request->user()));
    }

    public function battle(
        Request $request,
        string $battleRequestId,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->battle($request->user(), $battleRequestId));
    }

    /** @param callable(): array<mixed> $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()]);
        } catch (UndergroundRuntimeException $exception) {
            $status = $exception->errorCode === 'underground_secretary_missing'
                || $exception->errorCode === 'underground_battle_not_found'
                ? 404
                : 409;

            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $status);
        }
    }
}
