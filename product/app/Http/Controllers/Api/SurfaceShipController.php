<?php

namespace App\Http\Controllers\Api;

use App\Application\SurfaceShipCourseService;
use App\Application\SurfaceShipNameService;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Map\GridCoordinate;
use App\Domain\Nation\GivenName;
use App\Domain\Nation\NationProfileText;
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

        $ships = Ship::activeForNation($nation->world_id, $nation->id)
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
                'custom_name' => $ship->custom_name,
                'sort_order' => $definition->sortOrder,
                'x' => $ship->cell->x,
                'y' => $ship->cell->y,
                'current_hp' => $ship->current_hp,
                'max_hp' => $ship->max_hp,
                ...$ship->course(),
                'movement_mode' => $definition->movementMode,
                'key' => $definition->key,
                'undersea_construction' => $definition->underseaConstruction,
                'construction_cost_multiplier' => $definition->constructionCostMultiplier,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);

        $settings = $nation->world()->with('rulesetVersion')->firstOrFail()->rulesetVersion->settings;
        $shipTypes = [];
        foreach ($catalog->definitions($settings) as $definition) {
            if (! $definition->playerBuildable) {
                continue;
            }
            $shipTypes[] = [
                'key' => $definition->key,
                'name' => $definition->name,
                'count' => $ships->where('ship_type_key', $definition->key)->count(),
                'capacity' => $catalog->capacityPerType($settings, $definition),
            ];
        }

        return response()->json(['data' => $rows, 'meta' => ['ship_types' => $shipTypes]]);
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
            ...$updated->course(),
        ]]);
    }

    public function updateName(
        Request $request,
        Nation $nation,
        Ship $ship,
        SurfaceShipNameService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['present', 'nullable', 'string', 'max:'.GivenName::MAX_LENGTH, 'regex:'.NationProfileText::SINGLE_LINE_PATTERN],
        ], attributes: ['name' => '船の名前']);
        try {
            $updated = $service->rename($request->user(), $nation, $ship, $validated['name']);
        } catch (ResetRequiredException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => ResetRequiredException::ERROR_CODE], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => (int) $updated->id, 'custom_name' => $updated->custom_name]]);
    }

    public function updateDestination(
        Request $request,
        Nation $nation,
        Ship $ship,
        SurfaceShipCourseService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'destination' => ['present', 'nullable', 'array:x,y', 'size:2'],
            'destination.x' => ['required_with:destination', 'integer', 'between:-2147483648,2147483647'],
            'destination.y' => ['required_with:destination', 'integer', 'between:-2147483648,2147483647'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $destination = $validated['destination'];
        try {
            $updated = $service->updateDestination(
                $request->user(), $nation, $ship,
                $destination === null ? null : new GridCoordinate((int) $destination['x'], (int) $destination['y']),
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

        return response()->json(['data' => ['id' => (int) $updated->id, ...$updated->course()]]);
    }
}
