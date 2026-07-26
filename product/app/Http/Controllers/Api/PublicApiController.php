<?php

namespace App\Http\Controllers\Api;

use App\Application\MapChunkService;
use App\Application\PublicWorldService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MapSpaceResource;
use App\Http\Resources\WorldResource;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\World;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PublicApiController extends Controller
{
    public function worlds(): AnonymousResourceCollection
    {
        return WorldResource::collection(World::query()->orderBy('id')->get());
    }

    public function summary(World $world, PublicWorldService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary($world)]);
    }

    public function rankings(World $world, PublicWorldService $service): JsonResponse
    {
        return response()->json(['data' => $service->rankings($world)]);
    }

    public function events(World $world, PublicWorldService $service): JsonResponse
    {
        return response()->json(['data' => $service->recentEvents($world)]);
    }

    public function mapSpaces(World $world): AnonymousResourceCollection
    {
        return MapSpaceResource::collection($world->mapSpaces()->orderBy('id')->get());
    }

    public function nation(Nation $nation, PublicWorldService $service): JsonResponse
    {
        return response()->json(['data' => $service->nation($nation)]);
    }

    public function chunk(
        Nation $nation,
        MapSpace $mapSpace,
        int $chunkX,
        int $chunkY,
        MapChunkService $chunks,
    ): JsonResponse {
        abort_unless($nation->world_id === $mapSpace->world_id, 404);

        return response()->json(['data' => $chunks->present($mapSpace, $chunkX, $chunkY, null)]);
    }
}
