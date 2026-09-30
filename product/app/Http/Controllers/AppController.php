<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class AppController extends Controller
{
    public function show(): Response
    {
        return response()->view('app')->withHeaders([
            'Cache-Control' => 'private, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function version(): JsonResponse
    {
        return response()->json([
            'data' => ['version' => (string) config('hakoniwa.application_version')],
        ])->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
