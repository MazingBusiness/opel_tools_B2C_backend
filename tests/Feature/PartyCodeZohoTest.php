<?php

namespace Tests\Feature;

use App\Modules\Address\Jobs\SyncAddressToZoho;
use App\Modules\Address\Models\Address;
use App\Modules\Address\Services\ZohoBooksContacts;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class PartyCodeZohoTest extends TestCase
{
    use RefreshDatabase;

    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'line1' => '12 MG Road',
            'city' => 'Bhubaneswar',
            'state' => 'Odisha',
            'pincode' => '751001',
            'type' => 'home',
        ], $overrides);
    }

    private function enableZoho(): void
    {
        config([
            'zoho_books.organization_id' => '600000001',
            'zoho_books.client_id' => 'cid',
            'zoho_books.client_secret' => 'secret',
            'zoho_books.refresh_token' => 'refresh',
            'zoho_books.party_code_field' => 'cf_party_code',
            'zoho_books.accounts_base' => 'https://accounts.zoho.in',
            'zoho_books.api_base' => 'https://www.zohoapis.in/books/v3',
        ]);
    }

    public function test_new_user_gets_master_code_from_id(): void
    {
        $user = User::factory()->create();

        $this->assertSame('OPELB2C'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT), $user->refresh()->party_code);
    }

    public function test_addresses_get_suffixes_from_11_and_never_reuse_after_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $master = $user->refresh()->party_code;
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data');
        $this->assertSame($master.'11', $first['party_code']);
        $this->assertSame($master.'12', $second['party_code']);

        $this->deleteJson('/api/v1/addresses/'.$second['id'])->assertOk();
        $third = $this->postJson('/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data');
        $this->assertSame($master.'13', $third['party_code']);

        Queue::assertPushed(SyncAddressToZoho::class, 3);
    }

    public function test_address_limit_after_suffix_99(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->forceFill(['next_address_suffix' => 100])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/addresses', $this->addressPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
        $this->assertSame(0, Address::query()->count());
    }

    public function test_job_does_nothing_until_zoho_is_configured(): void
    {
        Http::fake();
        $address = $this->makeAddress();

        (new SyncAddressToZoho($address->id))->handle(app(ZohoBooksContacts::class));

        Http::assertNothingSent();
        $this->assertNull($address->refresh()->zoho_contact_id);
    }

    public function test_job_upserts_by_party_code_and_stores_contact_id(): void
    {
        $this->enableZoho();
        Cache::flush();
        Http::fake([
            'accounts.zoho.in/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.zohoapis.in/*' => Http::response(['code' => 0, 'contact' => ['contact_id' => '4600000123']]),
        ]);
        $address = $this->makeAddress();

        (new SyncAddressToZoho($address->id))->handle(app(ZohoBooksContacts::class));

        $this->assertSame('4600000123', $address->refresh()->zoho_contact_id);
        $this->assertNotNull($address->zoho_synced_at);
        Http::assertSent(function (Request $request) use ($address) {
            return $request->method() === 'PUT'
                && str_starts_with($request->url(), 'https://www.zohoapis.in/books/v3/contacts?organization_id=600000001')
                && $request->hasHeader('Authorization', 'Zoho-oauthtoken tok')
                && $request->hasHeader('X-Unique-Identifier-Key', 'cf_party_code')
                && $request->hasHeader('X-Unique-Identifier-Value', $address->party_code)
                && $request->hasHeader('X-Upsert', 'true')
                && $request['contact_name'] === 'Rahul Sharma '.$address->party_code
                && $request['custom_fields'] === [['api_name' => 'cf_party_code', 'value' => $address->party_code]];
        });

        // Already linked: a second run makes no call.
        Http::fake();
        (new SyncAddressToZoho($address->id))->handle(app(ZohoBooksContacts::class));
        Http::assertNothingSent();
    }

    public function test_zoho_error_throws_so_the_queue_retries(): void
    {
        $this->enableZoho();
        Cache::flush();
        Http::fake([
            'accounts.zoho.in/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.zohoapis.in/*' => Http::response(['code' => 1002, 'message' => 'Invalid value'], 400),
        ]);
        $address = $this->makeAddress();

        $this->expectException(RuntimeException::class);
        try {
            (new SyncAddressToZoho($address->id))->handle(app(ZohoBooksContacts::class));
        } finally {
            $this->assertNull($address->refresh()->zoho_contact_id);
        }
    }

    private function makeAddress(): Address
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data.id');

        return Address::query()->findOrFail($id);
    }
}
