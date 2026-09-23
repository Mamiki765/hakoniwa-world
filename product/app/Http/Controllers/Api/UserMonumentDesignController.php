<?php

namespace App\Http\Controllers\Api;

use App\Application\UserMonumentDesignService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserMonumentDesignRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class UserMonumentDesignController extends Controller
{
    public function show(Request $request, UserMonumentDesignService $designs): JsonResponse
    {
        return response()->json(['data' => $designs->present($request->user())]);
    }

    public function save(SaveUserMonumentDesignRequest $request, UserMonumentDesignService $designs): JsonResponse
    {
        $image = $request->file('image');
        $designs->save($request->user(), $request->string('name')->value(), $image instanceof UploadedFile ? $image : null);

        return response()->json(['data' => $designs->present($request->user())]);
    }
}
