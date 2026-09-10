<?php

namespace App\Modules\Auth\Rules;

use App\Modules\Auth\Support\LoginIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidLoginIdentifier implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a phone number or email.');

            return;
        }

        try {
            LoginIdentifier::parse($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
