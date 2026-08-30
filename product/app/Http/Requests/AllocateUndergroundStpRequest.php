<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AllocateUndergroundStpRequest extends FormRequest
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
            'allocations' => ['required', 'array:vitality,might,finesse,spirit,agility', 'min:1'],
            'allocations.vitality' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'allocations.might' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'allocations.finesse' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'allocations.spirit' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'allocations.agility' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
