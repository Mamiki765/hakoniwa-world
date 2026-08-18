<?php

namespace App\Http\Controllers\Api;

use App\Application\InquiryPresenter;
use App\Application\InquirySubmissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitInquiryRequest;
use Illuminate\Http\JsonResponse;

final class InquiryController extends Controller
{
    public function store(
        SubmitInquiryRequest $request,
        InquirySubmissionService $service,
        InquiryPresenter $presenter,
    ): JsonResponse {
        $result = $service->submit(
            $request->user(),
            $request->string('submission_key')->value(),
            $request->string('category')->value(),
            $request->string('subject')->value(),
            $request->string('body')->value(),
            $request->file('attachment'),
        );

        return response()->json(
            ['data' => $presenter->submission($result['inquiry'])],
            $result['created'] ? 201 : 200,
        );
    }
}
