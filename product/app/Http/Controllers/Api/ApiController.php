<?php

namespace App\Http\Controllers\Api;

use App\Application\NationCreationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNationRequest;
use App\Http\Resources\MapChunkResource;
use App\Http\Resources\MapSpaceResource;
use App\Http\Resources\MeResource;
use App\Http\Resources\NationResource;
use App\Http\Resources\WorldResource;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\World;
use App\Services\AssetManifestResolver;
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

    public function chunk(MapSpace $mapSpace, int $chunkQ, int $chunkR, AssetManifestResolver $assets): MapChunkResource
    {
        $chunk = $mapSpace->chunks()->where('chunk_q', $chunkQ)->where('chunk_r', $chunkR)->first();
        $cells = MapCell::query()
            ->where('map_space_id', $mapSpace->id)->where('chunk_q', $chunkQ)->where('chunk_r', $chunkR)
            ->with(['terrain', 'facility', 'ownerNation:id,name'])->orderBy('r')->orderBy('q')->get();

        return new MapChunkResource([
            'world_id' => $mapSpace->world_id, 'map_space_id' => $mapSpace->id,
            'chunk_q' => $chunkQ, 'chunk_r' => $chunkR,
            'chunk_size' => config('hakoniwa.ruleset.chunk_size'),
            'version' => $chunk === null ? 0 : $chunk->version,
            'state' => $chunk === null ? 'empty' : 'generated',
            'cells' => $cells->map(function (MapCell $cell) use ($assets): array {
                $definition = $cell->facility ?? $cell->terrain;

                return [
                    'q' => $cell->q, 'r' => $cell->r,
                    'terrain' => $cell->terrain->key,
                    'facility' => $cell->facility?->key,
                    'owner_nation_id' => $cell->owner_nation_id,
                    'owner_name' => $cell->ownerNation?->name,
                    'population' => $cell->population,
                    'asset' => $assets->resolve($definition->asset_key, $definition->name),
                    'version' => $cell->version,
                    'updated_at' => $cell->updated_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function createNation(CreateNationRequest $request, NationCreationService $service): JsonResponse
    {
        try {
            $nation = $service->create($request->user(), World::query()->findOrFail($request->integer('world_id')), $request->string('name')->trim()->value());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return (new NationResource($nation))->response()->setStatusCode(201);
    }

    public function nation(Nation $nation): NationResource
    {
        return new NationResource($nation->load(['capital', 'resourceBalances.definition']));
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
