<?php

namespace App\Modules\Auth\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single source for the response shoppers get when their account is disabled.
 * OTP verify, Google login and the shopper.active middleware must all return
 * this exact body so clients can handle it uniformly.
 */
final class DisabledAccount
{
    public const MESSAGE = 'This account has been disabled. Please contact support.';

    public const CODE = 'account_disabled';

    /**
     * @return array{message: string, code: string}
     */
    public static function body(): array
    {
        return [
            'message' => self::MESSAGE,
            'code' => self::CODE,
        ];
    }

    public static function response(): JsonResponse
    {
        return response()->json(self::body(), Response::HTTP_FORBIDDEN);
    }
}
