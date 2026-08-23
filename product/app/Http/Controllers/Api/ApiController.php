<?php

namespace App\Http\Controllers\Api;

use App\Application\MapChunkService;
use App\Application\NationCreationService;
use App\Domain\Nation\NationCreationConflictException;
use App\Domain\Nation\NationNameConflictException;
use App\Domain\Nation\NationPlacementUnavailableException;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            ->join('nations', 'nations.id', '=', 'nation_memberships.nation_id')
            ->where('nation_memberships.user_id', $request->user()->id)
            ->where('nation_memberships.world_id', $mapSpace->world_id)
            ->whereIn('nations.state', ['active', 'dormant', 'recovery'])
            ->value('nation_memberships.nation_id');

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
                $request->string('request_key')->value(),
            );
        } catch (ResetRequiredException $exception) {
            return response()->json([
                'code' => ResetRequiredException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (NationNameConflictException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        } catch (NationCreationConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (NationPlacementUnavailableException $exception) {
            report($exception);

            return response()->json([
                'code' => NationPlacementUnavailableException::ERROR_CODE,
                'message' => '現在、安全に初期島を配置できません。時間を置いてもう一度お試しください。',
            ], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'code' => 'nation_creation_failed',
                'message' => '登録処理を完了できませんでした。時間を置いてもう一度お試しください。',
            ], 500);
        }

        return (new NationResource($nation))->response()->setStatusCode(201);
    }

    public function nation(Request $request, Nation $nation): NationResource
    {
        abort_unless(in_array($nation->state, ['active', 'dormant', 'recovery'], true), 404);

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
            ->whereHas('nation', fn ($query) => $query->whereIn('state', ['active', 'dormant', 'recovery']))
            ->with(['nation.capital', 'nation.resourceBalances.definition'])->first();

        return $membership === null
            ? response()->json(['data' => null])
            : new NationResource($membership->nation);
    }
}
