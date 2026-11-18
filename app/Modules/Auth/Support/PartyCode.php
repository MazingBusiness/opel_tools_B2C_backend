<?php

namespace App\Modules\Auth\Support;

use LogicException;

/**
 * B2C party codes. Master: OPELB2C + 6-digit user id (OPELB2C000001).
 * Address: master + 2-digit suffix from 11 to 99, never reused (OPELB2C00000111).
 * The fixed width keeps an address code splittable back into master + suffix.
 */
final class PartyCode
{
    public const PREFIX = 'OPELB2C';

    public const FIRST_SUFFIX = 11;

    public const LAST_SUFFIX = 99;

    public static function master(int $userId): string
    {
        if ($userId < 1 || $userId > 999_999) {
            throw new LogicException("User id {$userId} does not fit a 6-digit party code.");
        }

        return self::PREFIX.str_pad((string) $userId, 6, '0', STR_PAD_LEFT);
    }

    public static function address(string $master, int $suffix): string
    {
        if ($suffix < self::FIRST_SUFFIX || $suffix > self::LAST_SUFFIX) {
            throw new LogicException("Address suffix {$suffix} is outside 11-99.");
        }

        return $master.$suffix;
    }
}
