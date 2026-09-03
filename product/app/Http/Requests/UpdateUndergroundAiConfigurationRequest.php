<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUndergroundAiConfigurationRequest extends FormRequest
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
            'rules' => ['present', 'nullable', 'array', 'max:16'],
            'rules.*' => ['array'],
            'rules.*.conditions' => ['present', 'array', 'max:2'],
            'rules.*.conditions.*' => ['array'],
            'rules.*.action' => ['required', 'string', 'max:120'],
            'rules.*.jump_to' => ['sometimes', 'integer', 'min:1', 'max:16'],
        ];
    }
}
