<?php

namespace App\Http\Controllers\Api;

use App\Application\CompensationWarehouseService;
use App\Http\Controllers\Controller;
use App\Models\CompensationGrant;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompensationWarehouseController extends Controller
{
    public function index(
        Request $request,
        Nation $nation,
        CompensationWarehouseService $warehouse,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $this->isOwner($user, $nation), 403);

        return response()->json(['data' => $warehouse->pendingFor($user, $nation)]);
    }

    public function claim(
        Request $request,
        Nation $nation,
        CompensationGrant $compensationGrant,
        CompensationWarehouseService $warehouse,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $this->isOwner($user, $nation), 403);
        abort_unless($compensationGrant->nation_id === $nation->id
            && $compensationGrant->recipient_user_id === $user->id, 404);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
        ]);

        try {
            $result = $warehouse->claim($user, $nation, $compensationGrant, $validated['request_id']);
        } catch (DomainException $exception) {
            return response()->json([
                'code' => 'compensation_claim_failed',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $result]);
    }

    private function isOwner(User $user, Nation $nation): bool
    {
        return NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->exists();
    }
}
