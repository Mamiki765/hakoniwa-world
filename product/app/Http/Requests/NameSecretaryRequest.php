<?php

namespace App\Http\Requests;

use App\Domain\Nation\NationProfileText;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

final class NameSecretaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:30',
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
                new PlainText,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => NationProfileText::trimSpaces($this->input('name'))]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '秘書名'];
    }
}
