<?php

namespace App\Http\Requests;

use App\Domain\Secretary\SecretaryProfileContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSecretaryImagePreferencesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'show_ai_generated_images' => ['required', 'boolean'],
            'fallback' => ['required', 'string', Rule::in(array_keys(SecretaryProfileContract::FALLBACKS))],
        ];
    }
}
