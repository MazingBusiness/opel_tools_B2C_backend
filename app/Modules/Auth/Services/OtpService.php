<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\OtpChallenge;
use App\Modules\Auth\Services\Channels\EmailOtpChannel;
use App\Modules\Auth\Services\Channels\SmsAlertOtpChannel;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(
        private OtpCodeGenerator $codes,
        private EmailOtpChannel $email,
        private SmsAlertOtpChannel $sms,
    ) {}

    public function issue(LoginIdentifier $identifier): OtpChallenge
    {
        OtpChallenge::query()
            ->where('identifier', $identifier->value)
            ->where('channel', $identifier->channel)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->codes->generate();

        $challenge = OtpChallenge::query()->create([
            'channel' => $identifier->channel,
            'identifier' => $identifier->value,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds((int) config('otp.ttl_seconds')),
            'attempts' => 0,
        ]);

        $this->sender($identifier)->send($identifier, $code);

        return $challenge;
    }

    public function verify(LoginIdentifier $identifier, string $code): void
    {
        $challenge = OtpChallenge::query()
            ->where('identifier', $identifier->value)
            ->where('channel', $identifier->channel)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($challenge === null || $challenge->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired.',
            ]);
        }

        $maxAttempts = (int) config('otp.max_attempts');

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired.',
            ]);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            if (($challenge->attempts) >= $maxAttempts) {
                $challenge->forceFill(['consumed_at' => now()])->save();
            }

            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired.',
            ]);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
    }

    private function sender(LoginIdentifier $identifier): EmailOtpChannel|SmsAlertOtpChannel
    {
        return $identifier->channel === LoginIdentifier::CHANNEL_EMAIL
            ? $this->email
            : $this->sms;
    }
}
