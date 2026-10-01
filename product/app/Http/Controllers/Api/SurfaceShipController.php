<?php

namespace App\Http\Controllers\Api;

use App\Application\SurfaceShipCourseService;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Ruleset\ResetRequiredException;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Ship;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SurfaceShipController extends Controller
{
    public function index(Request $request, Nation $nation, SurfaceShipCatalog $catalog): JsonResponse
    {
        abort_unless(in_array($nation->state, ['active', 'dormant', 'recovery'], true), 404);
        abort_unless(NationMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)->exists(), 403);

        $ships = $nation->ships()->where('state', Ship::STATE_ACTIVE)
            ->where('world_id', $nation->world_id)
            ->whereHas('cell')
            ->with(['cell:id,x,y', 'rulesetVersion:id,settings'])
            ->orderBy('id')->get();
        $definitions = [];
        $rows = [];
        foreach ($ships as $ship) {
            $definitions[$ship->ruleset_version_id] ??= collect(
                $catalog->definitions($ship->rulesetVersion->settings),
            )->keyBy('key');
            $definition = $definitions[$ship->ruleset_version_id]->get($ship->ship_type_key);
            if ($definition === null || $ship->cell === null) {
                continue;
            }
            $rows[] = [
                'id' => $ship->id,
                'name' => $definition->name,
                'sort_order' => $definition->sortOrder,
                'x' => $ship->cell->x,
                'y' => $ship->cell->y,
                'current_hp' => $ship->current_hp,
                'max_hp' => $ship->max_hp,
                'heading' => $ship->heading,
                'movement_mode' => $definition->movementMode,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);

        return response()->json(['data' => $rows]);
    }

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
