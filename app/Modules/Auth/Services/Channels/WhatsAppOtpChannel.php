<?php

namespace App\Modules\Auth\Services\Channels;

use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Auth\Support\MaskedPhone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends the OTP via an approved Meta WhatsApp authentication template.
 *
 * A 2xx with messages[0].id means "accepted by Meta", NOT "delivered". Any API
 * failure throws so OtpService can fall back to SMS. Never logs the code or token.
 */
class WhatsAppOtpChannel
{
    public function isEnabled(): bool
    {
        return (bool) config('services.whatsapp.otp_enabled')
            && filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'))
            && filled(config('services.whatsapp.otp_template'))
            && filled(config('services.whatsapp.otp_language'));
    }

    /**
     * @return string Meta message id (wamid)
     */
    public function send(LoginIdentifier $identifier, string $code): string
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            trim((string) config('services.whatsapp.graph_version'), '/'),
            rawurlencode((string) config('services.whatsapp.phone_number_id')),
        );

        try {
            $response = Http::timeout(max(1, (int) config('services.whatsapp.timeout', 8)))
                ->acceptJson()
                ->withToken((string) config('services.whatsapp.access_token'))
                ->post($url, $this->payload($identifier->value, $code));
        } catch (Throwable $e) {
            Log::warning('WhatsApp OTP request failed', [
                'to' => MaskedPhone::mask($identifier->value),
                'error' => class_basename($e),
            ]);

            throw new RuntimeException('WhatsApp OTP send failed.', 0, $e);
        }

        $messageId = $response->json('messages.0.id');

        if (! $response->successful() || ! is_string($messageId) || $messageId === '') {
            Log::warning('WhatsApp OTP rejected', [
                'to' => MaskedPhone::mask($identifier->value),
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
                'error_subcode' => $response->json('error.error_subcode'),
                'error_message' => $response->json('error.message'),
                'fbtrace_id' => $response->json('error.fbtrace_id'),
            ]);

            throw new RuntimeException('WhatsApp OTP send failed.');
        }

        Log::info('WhatsApp OTP accepted', [
            'to' => MaskedPhone::mask($identifier->value),
            'message_id' => $messageId,
        ]);

        return $messageId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $to, string $code): array
    {
        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
        ];

        if (config('services.whatsapp.otp_button', true)) {
            // Copy-code / one-tap OTP button: Meta expects it as a URL button at index 0
            // whose single text parameter is the code.
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => (string) config('services.whatsapp.otp_template'),
                'language' => ['code' => (string) config('services.whatsapp.otp_language')],
                'components' => $components,
            ],
        ];
    }
}
