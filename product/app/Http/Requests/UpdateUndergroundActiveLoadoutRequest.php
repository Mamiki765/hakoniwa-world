<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUndergroundActiveLoadoutRequest extends FormRequest
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
            'slots' => ['required', 'array', 'size:5'],
            'slots.*' => ['nullable', 'string', 'max:100', 'distinct'],
        ];
    }
}
