<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EquipUndergroundEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'item_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
