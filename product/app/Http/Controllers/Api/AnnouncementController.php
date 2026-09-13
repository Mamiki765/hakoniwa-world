<?php

namespace App\Http\Controllers\Api;

use App\Application\AnnouncementBodyRenderer;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Rules\PlainText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class AnnouncementController extends Controller
{
    private const PAGE_SIZE = 10;

    public function latest(): AnonymousResourceCollection
    {
        return AnnouncementResource::collection($this->ordered()->limit(5)->get());
    }

    public function index(): AnonymousResourceCollection
    {
        return AnnouncementResource::collection($this->ordered()->paginate(self::PAGE_SIZE));
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($announcement);
    }

    public function store(Request $request): JsonResponse
    {
        $announcement = Announcement::query()->create($request->validate($this->rules($request)));

        return (new AnnouncementResource($announcement))->response()->setStatusCode(201);
    }

    public function update(Request $request, Announcement $announcement): AnnouncementResource
    {
        $announcement->update($request->validate($this->rules($request, $announcement)));

        return new AnnouncementResource($announcement->refresh());
    }

    public function destroy(Announcement $announcement): Response
    {
        $announcement->delete();

        return response()->noContent();
    }

    public function preview(Request $request, AnnouncementBodyRenderer $renderer): JsonResponse
    {
        $input = $request->validate(Arr::only($this->rules($request), ['body', 'body_format']));

        return response()->json(['data' => [
            'body_html' => ($input['body_format'] ?? Announcement::FORMAT_PLAIN_TEXT) === Announcement::FORMAT_MARKDOWN
                ? $renderer->render($input['body'])
                : null,
        ]]);
    }

    /** @return Builder<Announcement> */
    private function ordered(): Builder
    {
        return Announcement::query()->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return array<string, list<mixed>> */
    private function rules(Request $request, ?Announcement $announcement = null): array
    {
        $markdown = $request->input('body_format', $announcement->body_format ?? Announcement::FORMAT_PLAIN_TEXT)
            === Announcement::FORMAT_MARKDOWN;

        return [
            'title' => ['required', 'string', 'max:160', new PlainText],
            'body' => ['required', 'string', 'max:20000', ...($markdown ? [] : [new PlainText])],
            'body_format' => ['sometimes', 'required', Rule::in([Announcement::FORMAT_PLAIN_TEXT, Announcement::FORMAT_MARKDOWN])],
        ];
    }
}
