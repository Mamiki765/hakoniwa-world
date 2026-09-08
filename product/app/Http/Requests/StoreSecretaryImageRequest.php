<?php

namespace App\Http\Requests;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Rules\PlainText;
use App\Rules\WebImageMime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreSecretaryImageRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'image' => ['required', File::types(['png', 'jpg', 'jpeg', 'webp', 'gif'])->max(10 * 1024), new WebImageMime],
            'creation_method' => ['required', 'string', Rule::in(array_keys(SecretaryProfileContract::CREATION_METHODS))],
            'credit' => ['required', 'string', 'max:160', new PlainText],
        ];
    }
}
