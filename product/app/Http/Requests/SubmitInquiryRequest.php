<?php

namespace App\Http\Requests;

use App\Domain\Inquiry\InquiryCategoryCatalog;
use App\Rules\InquiryImageMime;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class SubmitInquiryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'category' => ['required', 'string', Rule::in(app(InquiryCategoryCatalog::class)->keys())],
            'subject' => ['required', 'string', 'max:160', new PlainText],
            'body' => ['required', 'string', 'max:20000', new PlainText],
            'attachment' => [
                'nullable',
                File::types(['png', 'jpg', 'jpeg', 'webp', 'gif'])->max(10 * 1024),
                new InquiryImageMime,
            ],
        ];
    }
}
