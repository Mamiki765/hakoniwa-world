<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSecretaryProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'biography' => ['present', 'nullable', 'string', 'max:1000', new PlainText],
        ];
    }
}
