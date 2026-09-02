<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RespecUndergroundProfileRequest extends FormRequest
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
            'growth_path_key' => ['required', 'string', Rule::in([
                'martial_red',
                'guardianship_blue',
                'blessing_green',
                'free_black',
            ])],
        ];
    }
}
