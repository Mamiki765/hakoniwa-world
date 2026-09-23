<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use App\Rules\WebImageMime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class SaveUserMonumentDesignRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:40', new PlainText],
            'image' => ['sometimes', 'required', File::types(['gif'])->max(10 * 1024), new WebImageMime, 'dimensions:width=32,height=32'],
        ];
    }
}
