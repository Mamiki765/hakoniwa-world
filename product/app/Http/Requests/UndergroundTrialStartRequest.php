<?php

namespace App\Http\Requests;

use App\Application\Underground\UndergroundRuntimeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UndergroundTrialStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'trial_key' => ['sometimes', 'string', Rule::in(app(UndergroundRuntimeCatalog::class)->trialKeys())],
            'borrowed_secretary_ids' => ['prohibited'],
        ];
    }
}
