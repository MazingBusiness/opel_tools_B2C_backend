<?php

namespace App\Modules\Auth\Support;

use InvalidArgumentException;

final readonly class LoginIdentifier
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public function __construct(
        public string $channel,
        public string $value,
    ) {}

    public static function parse(string $raw): self
    {
        $identifier = trim($raw);

        if ($identifier === '') {
            throw new InvalidArgumentException('Enter a phone number or email.');
        }

        if (str_contains($identifier, '@')) {
            $email = strtolower($identifier);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Enter a valid email address.');
            }

            return new self(self::CHANNEL_EMAIL, $email);
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        if (strlen($digits) !== 12 || ! str_starts_with($digits, '91')) {
            throw new InvalidArgumentException('Enter a valid 10-digit Indian mobile number.');
        }

        $subscriber = substr($digits, 2);

        if (! preg_match('/^[6-9]\d{9}$/', $subscriber)) {
            throw new InvalidArgumentException('Enter a valid 10-digit Indian mobile number.');
        }

        return new self(self::CHANNEL_SMS, $digits);
    }
}
