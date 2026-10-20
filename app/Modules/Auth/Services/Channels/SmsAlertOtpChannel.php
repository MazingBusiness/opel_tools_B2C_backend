<?php

namespace App\Modules\Auth\Services\Channels;

use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Auth\Support\MaskedPhone;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsAlertOtpChannel
{
    /**
     * @return string 'sms' when handed to SMS Alert, 'log' when credentials are missing (local dev)
     */
    public function send(LoginIdentifier $identifier, string $code): string
    {
        $apiKey = config('services.smsalert.api_key');
        $sender = config('services.smsalert.sender');

        if (! filled($apiKey) || ! filled($sender)) {
            Log::info('SMS Alert OTP (credentials missing; logged for local use)', [
                'identifier' => MaskedPhone::mask($identifier->value),
                'code' => $code,
            ]);

            return 'log';
        }

        $text = str_replace('{code}', $code, (string) config('services.smsalert.otp_text'));

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->asForm()
                ->post('https://www.smsalert.co.in/api/push.json', [
                    'apikey' => $apiKey,
                    'sender' => $sender,
                    'mobileno' => $identifier->value,
                    'text' => $text,
                ])
                ->throw();
        } catch (RequestException $exception) {
            Log::error('SMS Alert OTP send failed', [
                'identifier' => MaskedPhone::mask($identifier->value),
                'status' => $exception->response?->status(),
            ]);

            throw new RuntimeException('Unable to send OTP right now.', 0, $exception);
        }

        $status = $response->json('status');

        if (is_string($status) && strtolower($status) === 'error') {
            Log::error('SMS Alert OTP rejected', [
                'identifier' => MaskedPhone::mask($identifier->value),
                'description' => $response->json('description'),
            ]);

            throw new RuntimeException('Unable to send OTP right now.');
        }

        return 'sms';
    }
}
