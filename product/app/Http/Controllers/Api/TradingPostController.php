<?php

namespace App\Http\Controllers\Api;

use App\Application\TradingPostService;
use App\Domain\Ruleset\ResetRequiredException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Http\Controllers\Controller;
use App\Models\AuctionListing;
use App\Models\Nation;
use App\Models\ResourceDefinition;
use App\Models\SecretaryItemInstance;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TradingPostController extends Controller
{
    public function index(Request $request, World $world, TradingPostService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $service->index($request->user(), $world)]);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return $this->domainError($exception);
        }
    }

    public function store(Request $request, Nation $nation, TradingPostService $service): JsonResponse
    {
        $validated = $request->validate([
            'product_type' => ['required', Rule::in(['resource', 'item'])],
            'resource_definition_id' => ['nullable', 'integer', 'min:1', 'required_if:product_type,resource'],
            'item_instance_id' => ['nullable', 'integer', 'min:1', 'required_if:product_type,item'],
            'quantity' => ['nullable', 'integer', 'min:1', 'required_if:product_type,resource'],
            'start_price' => ['required', 'integer', 'min:1'],
            'duration_turns' => ['required', 'integer'],
            'auto_relist' => ['required', 'boolean'],
        ]);

        try {
            $listing = $validated['product_type'] === 'resource'
                ? $service->listResource(
                    $request->user(),
                    $nation,
                    ResourceDefinition::query()->findOrFail((int) $validated['resource_definition_id']),
                    (int) $validated['quantity'],
                    (int) $validated['start_price'],
                    (int) $validated['duration_turns'],
                    (bool) $validated['auto_relist'],
                )
                : $service->listItem(
                    $request->user(),
                    $nation,
                    SecretaryItemInstance::query()->findOrFail((int) $validated['item_instance_id']),
                    (int) $validated['start_price'],
                    (int) $validated['duration_turns'],
                    (bool) $validated['auto_relist'],
                );

            return response()->json(['data' => ['id' => $listing->id]], 201);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return $this->domainError($exception);
        }
    }

    public function bid(
        Request $request,
        Nation $nation,
        AuctionListing $auctionListing,
        TradingPostService $service,
    ): JsonResponse {
        $validated = $request->validate(['amount' => ['required', 'integer', 'min:1']]);
        try {
            $listing = $service->bid(
                $request->user(),
                $nation,
                $auctionListing,
                (int) $validated['amount'],
            );

            return response()->json(['data' => [
                'id' => $listing->id,
                'current_price' => $listing->current_price,
                'bid_count' => $listing->bid_count,
            ]]);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return $this->domainError($exception);
        }
    }

    public function destroy(
        Request $request,
        Nation $nation,
        AuctionListing $auctionListing,
        TradingPostService $service,
    ): JsonResponse {
        try {
            $listing = $service->cancel($request->user(), $nation, $auctionListing);

            return response()->json(['data' => ['id' => $listing->id, 'status' => $listing->status]]);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return $this->domainError($exception);
        }
    }

    private function domainError(\Throwable $exception): JsonResponse
    {
        $conflict = $exception instanceof TurnAlreadyRunningException
            || $exception instanceof UnresolvedNextTurnRunException
            || $exception instanceof ResetRequiredException;

        return response()->json(['message' => $exception->getMessage()], $conflict ? 409 : 422);
    }
}
