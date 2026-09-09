<?php

namespace App\Http\Controllers\Api;

use App\Application\DailyLoginRewardService;
use App\Application\DailyQuestService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyRewardController extends Controller
{
    public function login(Request $request, DailyLoginRewardService $rewards): JsonResponse
    {
        return response()->json(['data' => $rewards->claim($request->user())]);
    }

    public function developmentOpened(Request $request, DailyQuestService $quests): JsonResponse
    {
        return response()->json(['data' => $quests->recordDevelopmentOpened($request->user())]);
    }
}
