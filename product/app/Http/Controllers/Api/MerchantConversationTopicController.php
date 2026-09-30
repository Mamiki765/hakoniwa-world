<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantConversationTopic;
use App\Rules\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MerchantConversationTopicController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'topics' => MerchantConversationTopic::query()->where('enabled', true)
                ->orderBy('id')->get(['id', 'question', 'answer']),
        ]]);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => [
            'topics' => MerchantConversationTopic::query()->orderByDesc('id')->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $topic = new MerchantConversationTopic;
        $topic->fill($request->validate($this->rules()));
        $topic->created_by_user_id = $request->user()->id;
        $topic->updated_by_user_id = $request->user()->id;
        $topic->save();

        return response()->json(['data' => $topic], 201);
    }

    public function update(Request $request, MerchantConversationTopic $merchantConversationTopic): JsonResponse
    {
        $merchantConversationTopic->fill($request->validate($this->rules()));
        $merchantConversationTopic->updated_by_user_id = $request->user()->id;
        $merchantConversationTopic->save();

        return response()->json(['data' => $merchantConversationTopic->refresh()]);
    }

    public function destroy(MerchantConversationTopic $merchantConversationTopic): Response
    {
        $merchantConversationTopic->delete();

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:1000', new PlainText],
            'answer' => ['required', 'string', 'max:4000', new PlainText],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
