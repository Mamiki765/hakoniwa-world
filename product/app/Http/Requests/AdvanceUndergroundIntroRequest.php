<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdvanceUndergroundIntroRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in([
                'initial_story_complete',
                'escape_complete',
                'shopkeeper_encounter_complete',
                'special_loss_aftermath_complete',
                'shop_explanation_complete',
            ])],
        ];
    }
}
