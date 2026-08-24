<?php

namespace App\Http\Requests;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Rules\PlainText;
use App\Rules\WebImageMime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreSecretaryMainImageRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::types(['png', 'jpg', 'jpeg', 'webp', 'gif'])->max(10 * 1024),
                new WebImageMime,
            ],
            'creation_method' => ['required', 'string', Rule::in(array_keys(SecretaryProfileContract::CREATION_METHODS))],
            'credit' => ['nullable', 'string', 'max:160', new PlainText],
        ];
    }
}
