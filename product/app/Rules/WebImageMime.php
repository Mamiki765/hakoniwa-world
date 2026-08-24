<?php

namespace App\Rules;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class WebImageMime implements ValidationRule
{
    private const MAX_DIMENSION = 12_000;

    private const MAX_PIXELS = 40_000_000;

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

            return;
        }

        $dimensions = @getimagesize($path);
        $width = is_array($dimensions) ? $dimensions[0] : null;
        $height = is_array($dimensions) ? $dimensions[1] : null;
        $dimensionMime = is_array($dimensions) ? $dimensions['mime'] : null;
        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width > intdiv(self::MAX_PIXELS, $height)
            || $dimensionMime !== $mime) {
            $fail('The :attribute field must be a readable image up to 12000 pixels per side and 40000000 total pixels.');
        }
    }
}
