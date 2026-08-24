<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryEquipmentService;
use App\Application\SecretaryItemEffectContextResolver;
use App\Application\SecretaryNamingService;
use App\Application\SecretaryPresenter;
use App\Application\SecretaryProfilePresenter;
use App\Application\SecretaryProfileService;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryEquipmentValidationException;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\NameSecretaryRequest;
use App\Http\Requests\StoreSecretaryMainImageRequest;
use App\Http\Requests\UpdateSecretaryEquipmentRequest;
use App\Http\Requests\UpdateSecretaryImagePreferencesRequest;
use App\Http\Requests\UpdateSecretaryMainImageMetadataRequest;
use App\Http\Requests\UpdateSecretaryProfileRequest;
use App\Models\Secretary;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class SecretaryController extends Controller
{
    public function show(
        Request $request,
        SecretaryPresenter $presenter,
        SecretaryItemEffectContextResolver $effectContexts,
    ): JsonResponse {
        $secretary = Secretary::query()->where('user_id', $request->user()->id)
            ->with(['skills', 'itemInstances'])->first();
        if (! $secretary instanceof Secretary) {
            return response()->json(['data' => null]);
        }
        try {
            $projection = $effectContexts->resolve(
                $request->user(),
                $this->equipmentWorldId($request),
            );
        } catch (SecretaryEquipmentValidationException $exception) {
            return response()->json([
                'code' => SecretaryEquipmentValidationException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $presenter->present($secretary, $projection, $request->user())]);
    }

    public function publicShow(
        Request $request,
        Secretary $secretary,
        SecretaryProfilePresenter $presenter,
        SecretaryItemEffectContextResolver $effectContexts,
    ): JsonResponse {
        if ($secretary->name === null) {
            abort(404);
        }
        $secretary->load(['user', 'skills', 'itemInstances']);
        try {
            $projection = $effectContexts->resolveForPublicProfile(
                $secretary->user,
                $this->equipmentWorldId($request),
            );
        } catch (SecretaryEquipmentValidationException $exception) {
            return response()->json([
                'code' => SecretaryEquipmentValidationException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $presenter->present($secretary, $request->user(), $projection)]);
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

        return response()->json(['data' => $presenter->present($secretary, viewer: $request->user())]);
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

        return response()->json(['data' => $presenter->present($secretary, viewer: $request->user())]);
    }

    public function equipmentOptions(
        Request $request,
        string $slot,
        SecretaryEquipmentService $equipment,
    ): JsonResponse {
        try {
            $options = $equipment->options(
                $request->user(),
                $this->equipmentSlot($slot),
                $this->equipmentWorldId($request),
            );
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
        string $slot,
        SecretaryEquipmentService $equipment,
        SecretaryPresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $equipment->mutate(
                $request->user(),
                $this->equipmentSlot($slot),
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

        return response()->json(['data' => $presenter->present($secretary, viewer: $request->user())]);
    }

    public function updateProfile(
        UpdateSecretaryProfileRequest $request,
        SecretaryProfileService $service,
        SecretaryProfilePresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $service->updateBiography(
                $request->user(),
                $request->string('biography')->value(),
            );
        } catch (SecretaryNotFoundException $exception) {
            return $this->secretaryNotFound($exception);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['biography' => $exception->getMessage()]);
        }

        return response()->json(['data' => $presenter->present($secretary, $request->user())]);
    }

    public function storeMainImage(
        StoreSecretaryMainImageRequest $request,
        SecretaryProfileService $service,
        SecretaryProfilePresenter $presenter,
    ): JsonResponse {
        $image = $request->file('image');
        if (! $image instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => '画像を選択してください。']);
        }
        try {
            $secretary = $service->replaceMainImage(
                $request->user(),
                $image,
                $request->string('creation_method')->value(),
                $request->string('credit')->value() ?: null,
            );
        } catch (SecretaryNotFoundException $exception) {
            return $this->secretaryNotFound($exception);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }

        return response()->json(['data' => $presenter->present($secretary, $request->user())]);
    }

    public function updateMainImageMetadata(
        UpdateSecretaryMainImageMetadataRequest $request,
        SecretaryProfileService $service,
        SecretaryProfilePresenter $presenter,
    ): JsonResponse {
        try {
            $secretary = $service->updateMainImageMetadata(
                $request->user(),
                $request->string('creation_method')->value(),
                $request->string('credit')->value() ?: null,
            );
        } catch (SecretaryNotFoundException $exception) {
            return $this->secretaryNotFound($exception);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['creation_method' => $exception->getMessage()]);
        }

        return response()->json(['data' => $presenter->present($secretary, $request->user())]);
    }

    public function updateImagePreferences(
        UpdateSecretaryImagePreferencesRequest $request,
        SecretaryProfileService $service,
    ): JsonResponse {
        $user = $service->updateImagePreferences(
            $request->user(),
            $request->boolean('show_ai_generated_images'),
            $request->filled('own_secretary_fallback')
                ? $request->string('own_secretary_fallback')->value()
                : $request->string('fallback')->value(),
        );

        return response()->json(['data' => [
            'configured' => true,
            'show_ai_generated_images' => $user->show_ai_generated_secretary_images,
            'own_secretary_fallback' => $user->secretary_image_fallback,
            'fallback' => $user->secretary_image_fallback,
        ]]);
    }

    private function equipmentSlot(string $slot): int
    {
        if (! in_array($slot, ['1', '2', '3', '4', '5'], true)) {
            throw new SecretaryEquipmentValidationException('装備slotは1から5で指定してください。');
        }

        return (int) $slot;
    }

    private function equipmentWorldId(Request $request): ?int
    {
        $worldId = $request->query('world_id');
        if ($worldId === null) {
            return null;
        }
        if (! is_string($worldId)
            || filter_var($worldId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new SecretaryEquipmentValidationException('装備効果を表示するWorldを確認してください。');
        }

        return (int) $worldId;
    }

    private function secretaryNotFound(SecretaryNotFoundException $exception): JsonResponse
    {
        return response()->json([
            'code' => SecretaryNotFoundException::ERROR_CODE,
            'message' => $exception->getMessage(),
        ], 404);
    }
}
