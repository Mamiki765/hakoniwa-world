<?php

namespace App\Http\Controllers\Api;

use App\Application\InquiryPresenter;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class AdminInquiryController extends Controller
{
    private const PAGE_SIZE = 10;

    public function latest(InquiryPresenter $presenter): JsonResponse
    {
        $items = $this->ordered()->limit(5)->get()
            ->map(fn (Inquiry $inquiry): array => $presenter->summary($inquiry));

        return response()->json(['data' => $items]);
    }

    public function index(InquiryPresenter $presenter): JsonResponse
    {
        $page = $this->ordered()->paginate(self::PAGE_SIZE);

        return response()->json([
            'data' => collect($page->items())->map(
                fn (Inquiry $inquiry): array => $presenter->summary($inquiry),
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(int $inquiryId, InquiryPresenter $presenter): JsonResponse
    {
        $inquiry = Inquiry::query()->with(['user', 'nation'])->findOrFail($inquiryId);

        return response()->json(['data' => $presenter->detail($inquiry)]);
    }

    /** @return Builder<Inquiry> */
    private function ordered(): Builder
    {
        return Inquiry::query()->with(['user', 'nation'])
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
