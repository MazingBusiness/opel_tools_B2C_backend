<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RequestOtpRequest;
use App\Modules\Auth\Http\Requests\VerifyOtpRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\OtpService;
use App\Modules\Auth\Support\DisabledAccount;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OtpController extends Controller
{
    public function request(RequestOtpRequest $request, OtpService $otp): JsonResponse
    {
        $identifier = LoginIdentifier::parse($request->validated('identifier'));

        $wait = $otp->cooldownRemaining($identifier);
        if ($wait > 0) {
            return response()->json([
                'ok' => false,
                'message' => "Please wait {$wait}s before requesting another code.",
                'retry_after' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        try {
            $challenge = $otp->issue($identifier, $request->validated('prefer'));
        } catch (RuntimeException $exception) {
            throw new HttpException(503, 'Unable to send OTP right now.', $exception);
        }

        return response()->json([
            'ok' => true,
            'channel' => $identifier->channel,
            // email | whatsapp | sms | log. whatsapp = accepted by Meta, not guaranteed delivered.
            'delivery' => $challenge->delivered_via,
            'expires_in' => (int) config('otp.ttl_seconds'),
            'resend_after' => (int) config('otp.resend_cooldown_seconds', 45),
        ]);
    }

    public function verify(VerifyOtpRequest $request, OtpService $otp, AuthService $auth): JsonResponse
    {
        $identifier = LoginIdentifier::parse($request->validated('identifier'));
        $otp->verify($identifier, $request->validated('code'));

        $user = $auth->loginOrRegister($identifier);

        if ($user->isDisabled()) {
            return DisabledAccount::response();
        }

        $token = $user->createToken('opel-b2c')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve(),
            'profile_complete' => $user->profileComplete(),
        ]);
    }
}
