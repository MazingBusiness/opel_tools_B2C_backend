<?php

namespace App\Modules\Address\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Address\Models\ZohoBooksToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Upserts one Zoho Books customer per address, keyed by the address party code in a unique
 * custom field. Upsert (PUT /contacts + X-Upsert) is what makes retries safe: a request that
 * created the contact but timed out on our side updates that same contact on the next try.
 */
class ZohoBooksContacts
{
    private const TOKEN_CACHE_KEY = 'zoho_books.access_token';

    public function enabled(): bool
    {
        foreach (['organization_id', 'client_id', 'client_secret', 'party_code_field'] as $key) {
            if (blank(config("zoho_books.{$key}"))) {
                return false;
            }
        }

        return filled($this->refreshToken());
    }

    public function authUrl(string $state): string
    {
        return rtrim((string) config('zoho_books.accounts_base'), '/').'/oauth/v2/auth?'.http_build_query([
            'scope' => config('zoho_books.scopes'),
            'client_id' => config('zoho_books.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('zoho_books.redirect_uri'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** Swaps the one-time code for a refresh token and stores it encrypted. Never logs or returns the token. */
    public function exchangeCode(string $code, ?string $accountsServer = null): void
    {
        $response = Http::asForm()->timeout(20)->post($this->accountsBase($accountsServer).'/oauth/v2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('zoho_books.client_id'),
            'client_secret' => config('zoho_books.client_secret'),
            'redirect_uri' => config('zoho_books.redirect_uri'),
        ]);

        $refresh = $response->json('refresh_token');
        if (! $response->successful() || blank($refresh)) {
            throw new RuntimeException('Zoho Books code exchange failed: HTTP '.$response->status().', '.(string) $response->json('error', 'no refresh token returned'));
        }

        ZohoBooksToken::query()->delete();
        ZohoBooksToken::create(['refresh_token' => (string) $refresh]);
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    private function refreshToken(): ?string
    {
        $stored = ZohoBooksToken::query()->latest('id')->value('refresh_token');

        return filled($stored) ? (string) $stored : (config('zoho_books.refresh_token') ?: null);
    }

    /** Refresh and API calls use the configured data centre, so refuse a consent from any other one. */
    private function accountsBase(?string $server): string
    {
        $configured = rtrim((string) config('zoho_books.accounts_base'), '/');
        if (filled($server) && rtrim((string) $server, '/') !== $configured) {
            throw new RuntimeException('Zoho account is on a different data centre than ZOHO_BOOKS_ACCOUNTS_BASE.');
        }

        return $configured;
    }

    /**
     * Contact custom fields, so the party code field's API name can be read once instead of guessed.
     *
     * @return list<array{label: string, api_name: string, data_type: string}>
     */
    public function contactCustomFields(): array
    {
        if (blank(config('zoho_books.organization_id')) || blank($this->refreshToken())) {
            throw new RuntimeException('Set ZOHO_BOOKS_ORGANIZATION_ID and run zoho:books-connect first.');
        }

        $response = Http::withToken($this->accessToken(), 'Zoho-oauthtoken')->timeout(20)
            ->get($this->url('/settings/fields').'&entity=contact&filter_custom_fields=true');

        if (! $response->successful() || (int) $response->json('code', -1) !== 0) {
            throw new RuntimeException('Zoho Books field list failed: HTTP '.$response->status().', '.(string) $response->json('message', 'no message'));
        }

        return array_map(fn (array $f) => [
            'label' => (string) ($f['label'] ?? ''),
            'api_name' => (string) ($f['api_name'] ?? ''),
            'data_type' => (string) ($f['data_type'] ?? ''),
        ], (array) $response->json('fields', []));
    }

    /**
     * @return string Zoho contact_id
     */
    public function upsert(Address $address): string
    {
        $address->loadMissing('user');
        $field = (string) config('zoho_books.party_code_field');

        $response = Http::withToken($this->accessToken(), 'Zoho-oauthtoken')
            ->withHeaders([
                'X-Unique-Identifier-Key' => $field,
                'X-Unique-Identifier-Value' => $address->party_code,
                'X-Upsert' => 'true',
            ])
            ->timeout(20)
            ->put($this->url('/contacts'), $this->payload($address, $field));

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        $contactId = $response->json('contact.contact_id');
        if (! $response->successful() || (int) $response->json('code', -1) !== 0 || blank($contactId)) {
            throw new RuntimeException(sprintf(
                'Zoho Books contact upsert failed for %s: HTTP %d, code %s, %s',
                $address->party_code,
                $response->status(),
                (string) $response->json('code', '-'),
                (string) $response->json('message', 'no message'),
            ));
        }

        return (string) $contactId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Address $address, string $field): array
    {
        $location = array_filter([
            'attention' => $address->name,
            'address' => $address->line1,
            'street2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'zip' => $address->pincode,
            'country' => 'India',
            'phone' => $address->phone,
        ], fn ($v) => filled($v));

        return [
            'contact_name' => trim("{$address->name} {$address->party_code}"),
            'contact_type' => 'customer',
            'customer_sub_type' => 'individual',
            'gst_treatment' => 'consumer',
            'billing_address' => $location,
            'shipping_address' => $location,
            'contact_persons' => [array_filter([
                'first_name' => $address->name,
                'mobile' => $address->phone,
                'email' => $address->user?->email,
                'is_primary_contact' => true,
            ], fn ($v) => filled($v))],
            'custom_fields' => [
                ['api_name' => $field, 'value' => $address->party_code],
            ],
        ];
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(20)->post(
            rtrim((string) config('zoho_books.accounts_base'), '/').'/oauth/v2/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken(),
                'client_id' => config('zoho_books.client_id'),
                'client_secret' => config('zoho_books.client_secret'),
            ],
        );

        $token = $response->json('access_token');
        if (! $response->successful() || blank($token)) {
            // Never include the response body: it can echo credentials.
            throw new RuntimeException('Zoho Books token refresh failed: HTTP '.$response->status().', '.(string) $response->json('error', 'no error code'));
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return (string) $token;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('zoho_books.api_base'), '/').$path
            .'?organization_id='.urlencode((string) config('zoho_books.organization_id'));
    }
}
