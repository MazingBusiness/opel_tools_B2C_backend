<?php

namespace App\Modules\Auth\Rules;

use App\Modules\Auth\Support\LoginIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidIndianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $identifier = LoginIdentifier::parse($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());

            return;
        }

        if ($identifier->channel !== LoginIdentifier::CHANNEL_SMS) {
            $fail('Enter a valid 10-digit Indian mobile number.');
        }
    }
}
