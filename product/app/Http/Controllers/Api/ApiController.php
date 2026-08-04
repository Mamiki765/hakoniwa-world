<?php

namespace App\Http\Controllers\Api;

use App\Application\MapChunkService;
use App\Application\NationCreationService;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNationRequest;
use App\Http\Resources\MapChunkResource;
use App\Http\Resources\MapSpaceResource;
use App\Http\Resources\MeResource;
use App\Http\Resources\NationResource;
use App\Http\Resources\WorldResource;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\World;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ApiController extends Controller
{
    public function me(Request $request): MeResource
    {
        return new MeResource($request->user()->load('authIdentities'));
    }

    public function worlds(): AnonymousResourceCollection
    {
        return WorldResource::collection(World::query()->orderBy('id')->get());
    }

    public function mapSpaces(World $world): AnonymousResourceCollection
    {
        return MapSpaceResource::collection($world->mapSpaces()->orderBy('id')->get());
    }

    public function chunk(Request $request, MapSpace $mapSpace, int $chunkX, int $chunkY, MapChunkService $chunks): MapChunkResource
    {
        $viewerNationId = NationMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('world_id', $mapSpace->world_id)
            ->value('nation_id');

        return new MapChunkResource($chunks->present(
            $mapSpace,
            $chunkX,
            $chunkY,
            $viewerNationId === null ? null : (int) $viewerNationId,
        ));
    }

    public function createNation(CreateNationRequest $request, NationCreationService $service): JsonResponse
    {
        try {
            $nation = $service->create(
                $request->user(),
                World::query()->findOrFail($request->integer('world_id')),
                $request->string('name')->trim()->value(),
                $request->string('owner_name')->value(),
                $request->string('comment')->value(),
            );
        } catch (ResetRequiredException $exception) {
            return response()->json([
                'code' => ResetRequiredException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return (new NationResource($nation))->response()->setStatusCode(201);
    }

    public function nation(Request $request, Nation $nation): NationResource
    {
        $nation->load('capital');
        $isOwner = NationMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('nation_id', $nation->id)
            ->exists();
        if ($isOwner) {
            $nation->load('resourceBalances.definition');
        }

        return new NationResource($nation);
    }

    public function myNation(Request $request): NationResource|JsonResponse
    {
        $membership = NationMembership::query()->where('user_id', $request->user()->id)
            ->with(['nation.capital', 'nation.resourceBalances.definition'])->first();

        return $membership === null
            ? response()->json(['data' => null])
            : new NationResource($membership->nation);
    }
}
