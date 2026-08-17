<?php

namespace App\Rules;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class InquiryImageMime implements ValidationRule
{
    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = $value instanceof UploadedFile ? $value->getRealPath() : false;
        $mime = $path === false ? false : (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $fail('The :attribute field must be a PNG, JPEG, WebP, or GIF image.');
        }
    }
}
