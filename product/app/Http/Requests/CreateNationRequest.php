<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateNationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'world_id' => ['required', 'integer', 'exists:worlds,id'],
            'name' => [
                'required', 'string', 'min:2', 'max:30', 'regex:/^[^\p{Cc}\p{Cs}<>]+$/u',
                Rule::unique('nations', 'name')->where(fn ($query) => $query->where('world_id', $this->integer('world_id'))),
            ],
        ];
    }
}
