<?php

namespace App\Http\Requests;

use App\Application\Underground\UndergroundRuntimeService;
use Illuminate\Foundation\Http\FormRequest;

final class UndergroundHuntingGroundSkipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'hunting_ground_key' => ['required', 'string', 'max:64'],
            'execution_count' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.UndergroundRuntimeService::MAX_BULK_SKIP_EXECUTIONS,
            ],
            'borrowed_secretary_ids' => ['prohibited'],
        ];
    }
}
