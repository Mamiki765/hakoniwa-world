<?php

namespace App\Http\Controllers\Api;

use App\Application\PlayerIslandEventService;
use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlayerEventController extends Controller
{
    public function index(
        Request $request,
        Nation $nation,
        PlayerIslandEventService $events,
    ): JsonResponse {
        $canView = NationMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->exists();
        if (! $canView) {
            return response()->json(['message' => '自国の出来事だけを取得できます。'], 403);
        }

        $currentTurn = (int) $nation->world()->value('current_turn');
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'anchor_turn' => ['nullable', 'integer', 'min:1', "max:{$currentTurn}"],
        ]);

        return response()->json(['data' => $events->page(
            $nation,
            isset($validated['page']) ? (int) $validated['page'] : 1,
            isset($validated['anchor_turn']) ? (int) $validated['anchor_turn'] : null,
        )]);
    }
}
