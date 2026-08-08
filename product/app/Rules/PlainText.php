<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/<\s*\/?\s*[A-Za-z][^>]*>/u', $value) === 1) {
            $fail('The :attribute field must be plain text without HTML tags.');
        }
    }
}
