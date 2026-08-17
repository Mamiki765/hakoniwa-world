<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryNamingService;
use App\Application\SecretaryPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\NameSecretaryRequest;
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
}
