<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UndergroundExploreRequest extends FormRequest
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
            'hunting_ground_key' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
