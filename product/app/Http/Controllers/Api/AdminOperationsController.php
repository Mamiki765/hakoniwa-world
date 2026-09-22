<?php

namespace App\Http\Controllers\Api;

use App\Application\AdminOperationsService;
use App\Application\CompensationWarehouseService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminOperationsController extends Controller
{
    public function overview(Request $request, World $world, AdminOperationsService $operations): JsonResponse
    {
        return response()->json(['data' => $operations->overview($this->actor($request), $world)]);
    }

    public function purgeStatus(Request $request, AdminOperationsService $operations): JsonResponse
    {
        return response()->json(['data' => $operations->purgeStatus($this->actor($request))]);
    }

    public function advanceTurn(Request $request, World $world, AdminOperationsService $operations): JsonResponse
    {
        $input = $request->validate(['target_turn' => ['required', 'integer', 'min:2']]);
        try {
            return response()->json(['data' => $operations->advanceTurn($this->actor($request), $world, (int) $input['target_turn'])]);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function turnStatus(Request $request, World $world, AdminOperationsService $operations): JsonResponse
    {
        return response()->json(['data' => $operations->turnStatus($this->actor($request), $world)]);
    }

    public function preview(Request $request, World $world, string $operation, AdminOperationsService $operations): JsonResponse
    {
        $rules = match ($operation) {
            'distribution' => [
                'target_kind' => ['required', Rule::in(['all', 'nation', 'secretary', 'user'])],
                'selected_ids' => ['sometimes', 'array', 'max:5000'],
                'selected_ids.*' => ['integer', 'min:1'],
                'manual_ids' => ['nullable', 'string', 'max:2000'],
                'reason' => ['required', 'string', 'max:2000'],
                'assets' => ['required', 'array:'.implode(',', array_keys(CompensationWarehouseService::ASSETS))],
                'assets.*' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            ],
            'abandonment' => [
                'nation_id' => ['required', 'integer', 'min:1'],
                'public_reason' => ['required', 'string', 'max:2000'],
                'operator_note' => ['nullable', 'string', 'max:4000'],
            ],
            'purge' => [
                'profile_id' => ['required', 'integer', 'min:1'],
                'stream' => ['required', Rule::in(UndergroundReceiptRollupService::STREAMS)],
            ],
            default => abort(404),
        };
        $input = $request->validate($rules);
        if ($operation === 'distribution') {
            $input['selected_ids'] = array_map(intval(...), $input['selected_ids'] ?? []);
            $input['assets'] = array_map(intval(...), $input['assets']);
        }
        foreach (['nation_id', 'profile_id'] as $id) {
            if (isset($input[$id])) {
                $input[$id] = (int) $input[$id];
            }
        }
        try {
            return response()->json(['data' => $operations->preview($this->actor($request), $world, $operation, $input)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function apply(Request $request, World $world, string $operation, AdminOperationsService $operations): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:2000000']]);
        try {
            return response()->json(['data' => $operations->apply($this->actor($request), $world, $operation, $input['token'])]);
        } catch (DomainException|TurnAlreadyRunningException|UnresolvedNextTurnRunException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
