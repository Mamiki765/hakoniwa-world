<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryEquipmentService;
use App\Application\SecretaryNamingService;
use App\Application\SecretaryPresenter;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryEquipmentValidationException;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\NameSecretaryRequest;
use App\Http\Requests\UpdateSecretaryEquipmentRequest;
use App\Models\Secretary;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SecretaryController extends Controller
{
    public function show(Request $request, SecretaryPresenter $presenter): JsonResponse
    {
        $secretary = Secretary::query()->where('user_id', $request->user()->id)
            ->with(['skills', 'itemInstances'])->first();
        if (! $secretary instanceof Secretary) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $presenter->present($secretary)]);
    }

    public function name(
        NameSecretaryRequest $request,
        SecretaryNamingService $naming,
        SecretaryPresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $naming->name($request->user(), $request->string('name')->value());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return response()->json(['data' => $presenter->present($secretary)]);
    }

    public function rename(
        NameSecretaryRequest $request,
        SecretaryNamingService $naming,
        SecretaryPresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $naming->rename($request->user(), $request->string('name')->value());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return response()->json(['data' => $presenter->present($secretary)]);
    }

    public function equipmentOptions(
        Request $request,
        int $slot,
        SecretaryEquipmentService $equipment,
    ): JsonResponse {
        try {
            $options = $equipment->options($request->user(), $slot);
        } catch (SecretaryNotFoundException $exception) {
            return response()->json([
                'code' => SecretaryNotFoundException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 404);
        } catch (SecretaryEquipmentValidationException $exception) {
            return response()->json([
                'code' => SecretaryEquipmentValidationException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $options]);
    }

    public function updateEquipment(
        UpdateSecretaryEquipmentRequest $request,
        int $slot,
        SecretaryEquipmentService $equipment,
        SecretaryPresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $equipment->mutate(
                $request->user(),
                $slot,
                $request->integer('item_id') ?: null,
                $request->integer('expected_version'),
            );
        } catch (SecretaryNotFoundException $exception) {
            return response()->json([
                'code' => SecretaryNotFoundException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 404);
        } catch (SecretaryEquipmentConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (SecretaryEquipmentValidationException $exception) {
            return response()->json([
                'code' => SecretaryEquipmentValidationException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $presenter->present($secretary)]);
    }
}
