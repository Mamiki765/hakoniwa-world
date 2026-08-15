<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AbandonNationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirmation_name' => ['required', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['confirmation_name' => '確認用の島名'];
    }
}
