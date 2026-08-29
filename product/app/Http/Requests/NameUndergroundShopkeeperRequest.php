<?php

namespace App\Http\Requests;

use App\Domain\Nation\NationProfileText;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

final class NameUndergroundShopkeeperRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:255',
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
                new PlainText,
            ],
        ];
    }
}
