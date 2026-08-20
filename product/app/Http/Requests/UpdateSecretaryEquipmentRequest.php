<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSecretaryEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slot' => $this->route('slot')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'slot' => ['required', 'integer', 'between:1,5'],
            'item_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
