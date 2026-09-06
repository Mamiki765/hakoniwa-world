<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UndergroundTrialStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['trial_key' => ['sometimes', 'string', 'in:trial_01,trial_02']];
    }
}
