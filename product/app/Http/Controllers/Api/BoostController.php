<?php

namespace App\Http\Controllers\Api;

use App\Application\BoostReservationRejectedException;
use App\Application\BoostScheduleService;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BoostController extends Controller
{
    public function index(World $world, BoostScheduleService $service): JsonResponse
    {
        $status = $service->publicStatus($world);

        return response()->json(['data' => $status['occurrences'], 'meta' => [
            'server_now' => $status['server_now'], 'timezone' => $status['timezone']]]);
    }

    public function admin(Request $request, World $world, BoostScheduleService $service): JsonResponse
    {
        return response()->json(['data' => $service->adminStatus($this->actor($request), $world)]);
    }

    public function preview(Request $request, World $world, BoostScheduleService $service): JsonResponse
    {
        $input = $request->validate(['kind' => ['required', Rule::in(['turn_acceleration', 'hunting_ground'])],
            'title' => ['required', 'string', 'max:120'], 'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['nullable', 'string', 'max:40'], 'repeat' => ['sometimes', Rule::in(['none', 'daily', 'weekly'])],
            'additional_turns' => ['sometimes', 'integer', 'min:1', 'max:500'], 'interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'repeat_count' => ['sometimes', 'integer', 'min:1', 'max:52']]);
        try {
            return response()->json(['data' => $service->preview($this->actor($request), $world, $input)]);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function reserve(Request $request, World $world, BoostScheduleService $service): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:100000']]);
        try {
            return response()->json(['data' => $service->reserve($this->actor($request), $world, $input['token'])]);
        } catch (BoostReservationRejectedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'boost_reservation_rejected'], 409);
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function cancel(Request $request, World $world, int $occurrence, BoostScheduleService $service): JsonResponse
    {
        $input = $request->validate(['future_series' => ['sometimes', 'boolean']]);
        try {
            $service->cancel($this->actor($request), $world, $occurrence, (bool) ($input['future_series'] ?? false));
        } catch (DomainException|TurnAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => ['cancelled' => true]]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
