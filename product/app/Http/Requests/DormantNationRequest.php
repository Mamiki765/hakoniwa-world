<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DormantNationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['days' => ['required', 'integer', 'between:1,7']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['days' => '休止期間'];
    }
}
