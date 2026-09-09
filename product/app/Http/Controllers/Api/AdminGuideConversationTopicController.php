<?php

namespace App\Http\Controllers\Api;

use App\Application\Underground\GuideConversationUnlockCatalog;
use App\Http\Controllers\Controller;
use App\Models\GuideConversationTopic;
use App\Rules\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminGuideConversationTopicController extends Controller
{
    public function index(GuideConversationUnlockCatalog $unlocks): JsonResponse
    {
        return response()->json(['data' => [
            'topics' => GuideConversationTopic::query()->orderByDesc('id')->get(),
            'unlock_options' => $unlocks->options(),
        ]]);
    }

    public function store(Request $request, GuideConversationUnlockCatalog $unlocks): JsonResponse
    {
        $values = $request->validate($this->rules($unlocks));
        $topic = GuideConversationTopic::query()->create([
            ...$values,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $topic], 201);
    }

    public function update(
        Request $request,
        GuideConversationTopic $guideConversationTopic,
        GuideConversationUnlockCatalog $unlocks,
    ): JsonResponse {
        $guideConversationTopic->update([
            ...$request->validate($this->rules($unlocks)),
            'updated_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $guideConversationTopic->refresh()]);
    }

    public function destroy(GuideConversationTopic $guideConversationTopic): Response
    {
        $guideConversationTopic->delete();

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(GuideConversationUnlockCatalog $unlocks): array
    {
        return [
            'initial_line' => ['required', 'string', 'max:4000', new PlainText],
            'choice_1' => ['required', 'string', 'max:1000', new PlainText],
            'reply_1' => ['required', 'string', 'max:4000', new PlainText],
            'choice_2' => ['nullable', 'required_with:reply_2', 'string', 'max:1000', new PlainText],
            'reply_2' => ['nullable', 'required_with:choice_2', 'string', 'max:4000', new PlainText],
            'choice_3' => ['nullable', 'required_with:reply_3', 'string', 'max:1000', new PlainText],
            'reply_3' => ['nullable', 'required_with:choice_3', 'string', 'max:4000', new PlainText],
            'unlock_key' => ['required', 'string', Rule::in($unlocks->keys())],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
