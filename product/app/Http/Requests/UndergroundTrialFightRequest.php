<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UndergroundTrialFightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'run_key' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
