<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSecretaryPortraitPreferenceRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['portrait_preference' => ['required', 'string', Rule::in(['full_body', 'bust'])]];
    }
}
