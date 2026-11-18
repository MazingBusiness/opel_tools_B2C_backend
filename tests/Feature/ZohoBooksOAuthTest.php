<?php

namespace Tests\Feature;

use App\Modules\Address\Http\Controllers\ZohoBooksOAuthController;
use App\Modules\Address\Models\Address;
use App\Modules\Address\Models\ZohoBooksToken;
use App\Modules\Address\Services\ZohoBooksContacts;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoBooksOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = '/api/v1/zoho/books/oauth/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'zoho_books.organization_id' => '600000001',
            'zoho_books.client_id' => 'cid',
            'zoho_books.client_secret' => 'secret',
            'zoho_books.refresh_token' => null,
            'zoho_books.party_code_field' => 'cf_party_code',
            'zoho_books.redirect_uri' => 'https://api.opeltools.com/api/v1/zoho/books/oauth/callback',
        ]);
    }

    private function issueState(): string
    {
        $this->artisan('zoho:books-connect')->assertSuccessful();
        $key = DB::table('cache')->where('key', 'like', '%'.ZohoBooksOAuthController::STATE_PREFIX.'%')->value('key');
        $this->assertNotNull($key, 'connect command should store a state');

        return substr($key, strpos($key, ZohoBooksOAuthController::STATE_PREFIX) + strlen(ZohoBooksOAuthController::STATE_PREFIX));
    }

    public function test_auth_url_asks_for_offline_consent_with_exact_redirect(): void
    {
        $url = app(ZohoBooksContacts::class)->authUrl('abc');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.zoho.in/oauth/v2/auth?', $url);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('https://api.opeltools.com/api/v1/zoho/books/oauth/callback', $q['redirect_uri']);
        $this->assertSame('ZohoBooks.contacts.CREATE,ZohoBooks.contacts.UPDATE,ZohoBooks.contacts.READ,ZohoBooks.settings.READ', $q['scope']);
        $this->assertSame('abc', $q['state']);
    }

    public function test_callback_stores_refresh_token_encrypted_and_state_is_single_use(): void
    {
        config(['cache.default' => 'database']);
        Http::fake(['accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-plain', 'expires_in' => 3600])]);
        $zoho = app(ZohoBooksContacts::class);
        $this->assertFalse($zoho->enabled());

        $state = $this->issueState();
        $this->get(self::CALLBACK.'?code=c1&state='.$state)->assertOk()->assertSee('Zoho Books connected');

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'c1'
            && $r['redirect_uri'] === 'https://api.opeltools.com/api/v1/zoho/books/oauth/callback');
        $this->assertSame(1, ZohoBooksToken::count());
        $this->assertSame('rt-plain', ZohoBooksToken::first()->refresh_token);
        $this->assertNotSame('rt-plain', DB::table('zoho_books_tokens')->value('refresh_token'));
        $this->assertTrue($zoho->enabled());

        $this->get(self::CALLBACK.'?code=c2&state='.$state)->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_bad_state_or_denied_consent_never_calls_zoho(): void
    {
        config(['cache.default' => 'database']);
        Http::fake();

        $this->get(self::CALLBACK.'?code=c1&state=forged')->assertStatus(422);
        $state = $this->issueState();
        $this->get(self::CALLBACK.'?error=access_denied&state='.$state)->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, ZohoBooksToken::count());
    }

    public function test_failed_exchange_shows_no_zoho_body_and_stores_nothing(): void
    {
        config(['cache.default' => 'database']);
        Http::fake(['accounts.zoho.in/*' => Http::response(['error' => 'invalid_code', 'secret_echo' => 'leak'], 400)]);

        $state = $this->issueState();
        $this->get(self::CALLBACK.'?code=bad&state='.$state)->assertStatus(502)->assertDontSee('leak');
        $this->assertSame(0, ZohoBooksToken::count());
    }

    public function test_stored_token_is_used_for_refresh_over_env(): void
    {
        config(['zoho_books.refresh_token' => 'env-rt']);
        ZohoBooksToken::create(['refresh_token' => 'db-rt']);
        Cache::forget('zoho_books.access_token');
        Http::fake([
            'accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            '*/contacts*' => Http::response(['code' => 0, 'contact' => ['contact_id' => '99']]),
        ]);

        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id, 'name' => 'A', 'phone' => '9876543210', 'line1' => 'x', 'city' => 'c',
            'state' => 's', 'pincode' => '751001', 'type' => 'home', 'party_code' => $user->party_code.'11',
        ]);
        app(ZohoBooksContacts::class)->upsert($address);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/v2/token') && $r['refresh_token'] === 'db-rt');
    }

    public function test_consent_from_another_data_centre_is_refused(): void
    {
        config(['cache.default' => 'database']);
        Http::fake();

        $state = $this->issueState();
        $this->get(self::CALLBACK.'?code=c1&state='.$state.'&accounts-server='.urlencode('https://accounts.zoho.com'))->assertStatus(502);

        Http::assertNothingSent();
        $this->assertSame(0, ZohoBooksToken::count());
    }

    public function test_fields_command_lists_contact_custom_field_api_names(): void
    {
        ZohoBooksToken::create(['refresh_token' => 'db-rt']);
        Cache::forget('zoho_books.access_token');
        Http::fake([
            'accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            '*/settings/fields*' => Http::response(['code' => 0, 'fields' => [
                ['label' => 'Party Code', 'api_name' => 'cf_party_code', 'data_type' => 'string'],
            ]]),
        ]);

        $this->artisan('zoho:books-fields')
            ->expectsTable(['Label', 'API name', 'Type'], [['Party Code', 'cf_party_code', 'string']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/settings/fields?organization_id=600000001&entity=contact&filter_custom_fields=true'));
    }
}
