<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\ZohoPaymentToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZohoPaymentService
{
    public function authUrl(string $state = 'b2c_payment_oauth'): string
    {
        $accountId = (string) config('zoho_payments.account_id');
        $query = http_build_query([
            'scope' => config('zoho_payments.scope'),
            'client_id' => config('zoho_payments.client_id'),
            'soid' => config('zoho_payments.soid_prefix').'.'.$accountId,
            'state' => $state,
            'response_type' => 'code',
            'redirect_uri' => config('zoho_payments.oauth_redirect_uri'),
            'access_type' => 'offline',
        ]);

        return rtrim((string) config('zoho_payments.accounts_base'), '/').'/oauth/v2/org/auth?'.$query;
    }

    /**
     * Exchange authorization code for tokens and persist.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(
            rtrim((string) config('zoho_payments.accounts_base'), '/').'/oauth/v2/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => config('zoho_payments.client_id'),
                'client_secret' => config('zoho_payments.client_secret'),
                'redirect_uri' => config('zoho_payments.oauth_redirect_uri'),
                'code' => $code,
            ],
        );

        $data = $response->json() ?? [];
        if (! $response->successful() || empty($data['access_token'])) {
            Log::error('[ZohoPayment] OAuth code exchange failed', ['body' => $response->body()]);
            throw new RuntimeException('Failed to exchange Zoho OAuth code.');
        }

        $this->storeToken($data);

        return $data;
    }

    /**
     * @return array{access_token: string}|array{auth_required: true, auth_url: string}
     */
    public function accessTokenOrAuth(): array
    {
        $token = ZohoPaymentToken::query()->orderByDesc('id')->first();
        if (! $token || ! $token->refresh_token) {
            return [
                'auth_required' => true,
                'auth_url' => $this->authUrl(),
            ];
        }

        if ($token->expires_at && now()->greaterThanOrEqualTo($token->expires_at->subMinute())) {
            $this->refresh($token);
            $token->refresh();
        }

        return ['access_token' => $token->access_token];
    }

    public function refresh(ZohoPaymentToken $token): void
    {
        $response = Http::asForm()->post(
            rtrim((string) config('zoho_payments.accounts_base'), '/').'/oauth/v2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => config('zoho_payments.client_id'),
                'client_secret' => config('zoho_payments.client_secret'),
                'refresh_token' => $token->refresh_token,
            ],
        );

        $data = $response->json() ?? [];
        if (! $response->successful() || empty($data['access_token'])) {
            Log::error('[ZohoPayment] Refresh failed', ['body' => $response->body()]);
            throw new RuntimeException('Failed to refresh Zoho Payment token.');
        }

        $token->update([
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
        ]);
    }

    /**
     * @param  array{email?: string|null, phone?: string|null}  $customer
     * @return array{url: string, payment_link_id: string, expires_at: string}
     */
    public function createPaymentLink(Order $order, array $customer = []): array
    {
        $tok = $this->accessTokenOrAuth();
        if (! empty($tok['auth_required'])) {
            throw new RuntimeException('Zoho Payments OAuth required. Visit: '.$tok['auth_url']);
        }

        $expiresAt = now()->addDay()->toDateString();
        $payload = [
            'amount' => (float) $order->grand_total,
            'currency' => $order->currency ?: 'INR',
            'email' => $customer['email'] ?: 'orders@opel.local',
            'phone' => $this->nationalPhone($customer['phone'] ?? $order->shipping_phone),
            'reference_id' => $order->number,
            'description' => 'OPEL order '.$order->number,
            'expires_at' => $expiresAt,
            'notify_user' => false,
        ];

        $returnUrl = (string) config('zoho_payments.return_url');
        if ($this->isPublicHttpsUrl($returnUrl)) {
            $payload['return_url'] = $returnUrl;
        }

        $accountId = config('zoho_payments.account_id');
        $url = rtrim((string) config('zoho_payments.api_base'), '/').'/paymentlinks?account_id='.$accountId;

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$tok['access_token'],
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if ($response->failed()) {
            Log::error('[ZohoPayment] Create link failed', ['body' => $response->body()]);
            $message = $response->json('message');
            throw new RuntimeException(is_string($message) && $message !== ''
                ? $message
                : 'Failed to create Zoho payment link.');
        }

        $data = $response->json() ?? [];
        $pl = $data['payment_links'] ?? $data['payment_link'] ?? $data;
        $paymentUrl = (string) ($pl['url'] ?? '');
        $paymentLinkId = (string) ($pl['payment_link_id'] ?? '');

        if ($paymentUrl === '') {
            Log::error('[ZohoPayment] Link URL missing', ['data' => $data]);
            throw new RuntimeException('Zoho payment link URL missing.');
        }

        return [
            'url' => $paymentUrl,
            'payment_link_id' => $paymentLinkId,
            'expires_at' => $expiresAt,
        ];
    }


    /**
     * Server-side status check for a payment link (authoritative).
     *
     * @return array{status: string, payment_id: string|null, raw: array<string, mixed>}|null
     */
    public function fetchPaymentLink(string $paymentLinkId): ?array
    {
        $tok = $this->accessTokenOrAuth();
        if (! empty($tok['auth_required'])) {
            return null;
        }

        $accountId = config('zoho_payments.account_id');
        $url = rtrim((string) config('zoho_payments.api_base'), '/')
            .'/paymentlinks/'.rawurlencode($paymentLinkId)
            .'?account_id='.$accountId;

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$tok['access_token'],
            'Content-Type' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            Log::warning('[ZohoPayment] fetchPaymentLink failed', [
                'payment_link_id' => $paymentLinkId,
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json() ?? [];
        $pl = $data['payment_links'] ?? $data['payment_link'] ?? $data;
        $status = strtolower((string) ($pl['status'] ?? $data['status'] ?? ''));
        $paymentId = isset($pl['payment_id']) ? (string) $pl['payment_id'] : null;

        return [
            'status' => $status,
            'payment_id' => $paymentId,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    public function isPaidStatus(string $status): bool
    {
        return in_array(strtolower($status), ['paid', 'success', 'succeeded', 'completed'], true);
    }

    public function isFailedStatus(string $status): bool
    {
        return in_array(strtolower($status), ['failed', 'failure', 'cancelled', 'canceled', 'expired'], true);
    }

    private function nationalPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        return $digits !== '' ? $digits : null;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeToken(array $data): void
    {
        ZohoPaymentToken::query()->delete();
        ZohoPaymentToken::query()->create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);
    }
}
