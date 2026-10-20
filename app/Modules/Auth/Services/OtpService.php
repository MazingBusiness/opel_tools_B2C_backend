<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\OtpChallenge;
use App\Modules\Auth\Services\Channels\EmailOtpChannel;
use App\Modules\Auth\Services\Channels\SmsAlertOtpChannel;
use App\Modules\Auth\Services\Channels\WhatsAppOtpChannel;
use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Auth\Support\MaskedPhone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OtpService
{
    public function __construct(
        private OtpCodeGenerator $codes,
        private EmailOtpChannel $email,
        private SmsAlertOtpChannel $sms,
        private WhatsAppOtpChannel $whatsapp,
    ) {}

    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_WHATSAPP = 'whatsapp';

    public const DELIVERY_SMS = 'sms';

    public const DELIVERY_LOG = 'log';

    /**
     * Seconds until another code may be requested for this identifier (0 = now).
     */
    public function cooldownRemaining(LoginIdentifier $identifier): int
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds', 45);
        if ($cooldown <= 0) {
            return 0;
        }
        $last = OtpChallenge::query()
            ->where('identifier', $identifier->value)
            ->where('channel', $identifier->channel)
            ->latest('id')
            ->value('created_at');
        if ($last === null) {
            return 0;
        }
        $elapsed = now()->getTimestamp() - \Illuminate\Support\Carbon::parse($last)->getTimestamp();

        return max(0, $cooldown - $elapsed);
    }

    /**
     * @param  string|null  $prefer  'sms' to skip WhatsApp (shopper chose "Resend by SMS")
     */
    public function issue(LoginIdentifier $identifier, ?string $prefer = null): OtpChallenge
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

        $challenge->forceFill(['delivered_via' => $this->deliver($identifier, $code, $prefer)])->save();

        return $challenge;
    }

    /**
     * Email → mail. Phone → WhatsApp first (if enabled and not prefer=sms), SMS
     * fallback ONLY when the WhatsApp API call itself fails. A WhatsApp 2xx means
     * "accepted", not delivered — the shopper can still choose "Resend by SMS".
     */
    private function deliver(LoginIdentifier $identifier, string $code, ?string $prefer): string
    {
        if ($identifier->channel === LoginIdentifier::CHANNEL_EMAIL) {
            $this->email->send($identifier, $code);

            return self::DELIVERY_EMAIL;
        }

        if ($prefer !== self::DELIVERY_SMS && $this->whatsapp->isEnabled()) {
            try {
                $this->whatsapp->send($identifier, $code);

                return self::DELIVERY_WHATSAPP;
            } catch (RuntimeException) {
                Log::warning('WhatsApp OTP failed; falling back to SMS', [
                    'to' => MaskedPhone::mask($identifier->value),
                ]);
            }
        }

        return $this->sms->send($identifier, $code); // 'sms' | 'log'; throws if SMS fails too
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
}
