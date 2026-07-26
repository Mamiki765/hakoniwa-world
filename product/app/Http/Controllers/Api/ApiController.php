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
use App\Services\MapCellPresenter;
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

    public function chunk(Request $request, MapSpace $mapSpace, int $chunkX, int $chunkY, MapCellPresenter $presenter): MapChunkResource
    {
        $viewerNationId = NationMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('world_id', $mapSpace->world_id)
            ->value('nation_id');
        $rulesetVersionId = (int) $mapSpace->world()->value('ruleset_version_id');
        $chunk = $mapSpace->chunks()->where('chunk_x', $chunkX)->where('chunk_y', $chunkY)->first();
        $cells = MapCell::query()
            ->where('map_space_id', $mapSpace->id)->where('chunk_x', $chunkX)->where('chunk_y', $chunkY)
            ->with(['terrain', 'facility', 'ownerNation:id,name'])->orderBy('y')->orderBy('x')->get();

        $presentedCells = $cells->map(fn (MapCell $cell): array => $presenter->present(
            $cell,
            $viewerNationId === null ? null : (int) $viewerNationId,
            $rulesetVersionId,
        ))->values();
        $representationVersion = hash('sha256', json_encode($presentedCells, JSON_THROW_ON_ERROR));

        return new MapChunkResource([
            'world_id' => $mapSpace->world_id, 'map_space_id' => $mapSpace->id,
            'chunk_x' => $chunkX, 'chunk_y' => $chunkY,
            'chunk_size' => config('hakoniwa.ruleset.chunk_size'),
            'version' => $chunk === null ? 'empty' : $representationVersion,
            'state' => $chunk === null ? 'empty' : 'generated',
            'cells' => $presentedCells,
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
