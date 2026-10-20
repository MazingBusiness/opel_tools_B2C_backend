<?php

namespace App\Modules\Auth\Support;

/** Log-safe phone: keeps country code + last 4 digits ("91******3210"). */
final class MaskedPhone
{
    public static function mask(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) <= 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 2).str_repeat('*', strlen($digits) - 6).substr($digits, -4);
    }
}
