<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUndergroundRentalPartyRequest extends FormRequest
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
            'borrowed_secretary_ids' => ['present', 'array', 'max:3', 'list'],
            'borrowed_secretary_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
