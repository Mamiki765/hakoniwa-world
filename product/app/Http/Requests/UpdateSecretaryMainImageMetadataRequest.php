<?php

namespace App\Http\Requests;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSecretaryMainImageMetadataRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'creation_method' => ['required', 'string', Rule::in(array_keys(SecretaryProfileContract::CREATION_METHODS))],
            'credit' => ['nullable', 'string', 'max:160', new PlainText],
        ];
    }
}
