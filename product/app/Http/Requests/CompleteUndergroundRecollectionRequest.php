<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteUndergroundRecollectionRequest extends FormRequest
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
            'chapter' => ['required', 'integer', 'between:1,5'],
        ];
    }
}
