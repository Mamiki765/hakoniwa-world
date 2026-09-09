<?php

namespace App\Http\Requests;

use App\Application\Underground\UndergroundRuntimeService;
use Illuminate\Foundation\Http\FormRequest;

final class UndergroundTrialSkipRequest extends FormRequest
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
            'trial_key' => ['required', 'string', 'in:trial_01,trial_02'],
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
