<?php

namespace Tests\Feature;

use App\Modules\Address\Models\Address;
use App\Modules\Auth\Models\User;
use App\Modules\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->staff()->create([
            'name' => 'Ops Admin',
            'email' => 'admin@example.com',
        ]);
        $this->adminToken = $this->admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    private function makeOrder(User $user): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'number' => 'OPL-'.Str::upper(Str::random(8)),
            'shipping_name' => 'Ship To',
            'shipping_phone' => '919876543210',
            'shipping_line1' => '1 Street',
            'shipping_city' => 'Pune',
            'shipping_state' => 'Maharashtra',
            'shipping_pincode' => '411001',
        ]);
    }

    private function makeAddress(User $user, bool $default = false): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'name' => 'Home',
            'phone' => '919876543210',
            'line1' => '1 Street',
            'line2' => null,
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411001',
            'type' => 'home',
            'is_default' => $default,
        ]);
    }

    // ---- auth gate -------------------------------------------------------

    public function test_routes_require_admin_token(): void
    {
        $shopper = User::factory()->create();
        $shopperToken = $shopper->createToken('opel-b2c')->plainTextToken;

        $this->getJson('/api/v1/admin/users')->assertUnauthorized();

        $this->withToken($shopperToken)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)->getJson("/api/v1/admin/users/{$shopper->id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)->postJson("/api/v1/admin/users/{$shopper->id}/deactivate")->assertForbidden();

        $this->assertNull($shopper->refresh()->disabled_at);
    }

    // ---- list ------------------------------------------------------------

    public function test_list_returns_shoppers_only_with_envelope(): void
    {
        $shopper = User::factory()->create(['name' => 'Asha Shopper']);
        User::factory()->staff()->create(['email' => 'other-staff@example.com']);
        $this->makeOrder($shopper);
        $this->makeOrder($shopper);

        $response = $this->asAdmin()->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shopper->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.disabled_at', null)
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure([
                'ok',
                'data' => [['id', 'name', 'email', 'phone', 'status', 'disabled_at', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);

        $this->assertArrayNotHasKey('is_staff', $response->json('data.0'));
        $this->assertArrayNotHasKey('firebase_uid', $response->json('data.0'));
    }

    public function test_list_search_matches_name_email_phone(): void
    {
        $byName = User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'r1@example.com', 'phone' => '919000000001']);
        $byEmail = User::factory()->create(['name' => 'Someone', 'email' => 'findme@example.com', 'phone' => '919000000002']);
        $byPhone = User::factory()->create(['name' => 'Other', 'email' => 'o@example.com', 'phone' => '919876512345']);
        User::factory()->staff()->create(['name' => 'Ravi Staff', 'email' => 'ravi-staff@example.com']);

        $this->asAdmin()->getJson('/api/v1/admin/users?q=Ravi')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byName->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?q=findme')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byEmail->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?q=98765')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byPhone->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?q=%25')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_status_filter(): void
    {
        $active = User::factory()->create();
        $disabled = User::factory()->disabled()->create();

        $this->asAdmin()->getJson('/api/v1/admin/users?status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?status=disabled')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $disabled->id)
            ->assertJsonPath('data.0.status', 'disabled');

        $this->asAdmin()->getJson('/api/v1/admin/users?status=all')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->asAdmin()->getJson('/api/v1/admin/users?status=bogus')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_list_sorts_by_created_at_and_paginates(): void
    {
        $old = User::factory()->create(['created_at' => now()->subDays(3)]);
        $mid = User::factory()->create(['created_at' => now()->subDays(2)]);
        $new = User::factory()->create(['created_at' => now()->subDay()]);

        $this->asAdmin()->getJson('/api/v1/admin/users')
            ->assertOk()->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.2.id', $old->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?sort=created_at&dir=asc')
            ->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.2.id', $new->id);

        $this->asAdmin()->getJson('/api/v1/admin/users?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->asAdmin()->getJson('/api/v1/admin/users?sort=name')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');

        $this->assertNotNull($mid);
    }

    // ---- detail ----------------------------------------------------------

    public function test_show_returns_profile_addresses_and_orders_count(): void
    {
        $shopper = User::factory()->create(['name' => 'Detail Shopper']);
        $this->makeAddress($shopper);
        $default = $this->makeAddress($shopper, true);
        $this->makeOrder($shopper);

        $this->asAdmin()->getJson("/api/v1/admin/users/{$shopper->id}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.id', $shopper->id)
            ->assertJsonPath('user.name', 'Detail Shopper')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.orders_count', 1)
            ->assertJsonCount(2, 'user.addresses')
            ->assertJsonPath('user.addresses.0.id', $default->id)
            ->assertJsonPath('user.addresses.0.is_default', true)
            ->assertJsonStructure([
                'user' => [
                    'id', 'name', 'email', 'phone', 'avatar', 'status', 'disabled_at',
                    'email_verified_at', 'phone_verified_at', 'created_at', 'orders_count',
                    'addresses' => [[
                        'id', 'name', 'phone', 'line1', 'line2', 'city', 'state', 'pincode',
                        'type', 'is_default', 'created_at', 'updated_at',
                    ]],
                ],
            ]);
    }

    public function test_staff_ids_return_404_on_every_route(): void
    {
        $staff = User::factory()->staff()->create();
        $staffToken = $staff->createToken('opel-b2c-admin', ['admin']);

        $this->asAdmin()->getJson("/api/v1/admin/users/{$staff->id}")
            ->assertNotFound()->assertJsonPath('message', 'User not found.');
        $this->asAdmin()->postJson("/api/v1/admin/users/{$staff->id}/deactivate")->assertNotFound();
        $this->asAdmin()->postJson("/api/v1/admin/users/{$staff->id}/activate")->assertNotFound();

        // Acting admin cannot target themselves either.
        $this->asAdmin()->postJson("/api/v1/admin/users/{$this->admin->id}/deactivate")->assertNotFound();

        $this->assertNull($staff->refresh()->disabled_at);
        $this->assertNull($this->admin->refresh()->disabled_at);
        $this->assertNotNull(PersonalAccessToken::find($staffToken->accessToken->id));
    }

    public function test_unknown_and_malformed_ids_return_404(): void
    {
        $this->asAdmin()->getJson('/api/v1/admin/users/999999')->assertNotFound();
        $this->asAdmin()->getJson('/api/v1/admin/users/99999999999999999999999')->assertNotFound();
        $this->asAdmin()->getJson('/api/v1/admin/users/abc')->assertNotFound();
    }

    // ---- deactivate / activate ---------------------------------------------

    public function test_deactivate_sets_disabled_at_and_revokes_only_shopper_tokens(): void
    {
        $shopper = User::factory()->create();
        $other = User::factory()->create();

        $shopperToken1 = $shopper->createToken('opel-b2c');
        $shopperToken2 = $shopper->createToken('opel-b2c');
        // Defensive: a token with the admin ability must never be deleted here.
        $adminAbilityToken = $shopper->createToken('opel-b2c-admin', ['admin']);
        $otherToken = $other->createToken('opel-b2c');

        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.id', $shopper->id)
            ->assertJsonPath('user.status', 'disabled');

        $this->assertNotNull($shopper->refresh()->disabled_at);
        $this->assertNull(PersonalAccessToken::find($shopperToken1->accessToken->id));
        $this->assertNull(PersonalAccessToken::find($shopperToken2->accessToken->id));
        $this->assertNotNull(PersonalAccessToken::find($adminAbilityToken->accessToken->id));
        $this->assertNotNull(PersonalAccessToken::find($otherToken->accessToken->id));
        $this->assertNull($other->refresh()->disabled_at);

        // Acting admin's own session survives.
        $this->asAdmin()->getJson('/api/v1/admin/auth/me')->assertOk();
    }

    public function test_deactivated_shopper_old_token_no_longer_works(): void
    {
        $shopper = User::factory()->create();
        $plain = $shopper->createToken('opel-b2c')->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/deactivate")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivate_is_idempotent_and_keeps_original_timestamp(): void
    {
        $this->freezeSecond();
        $shopper = User::factory()->create();

        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/deactivate")->assertOk();
        $first = $shopper->refresh()->disabled_at;

        $this->travel(5)->minutes();

        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('user.disabled_at', $first->toIso8601String());

        $this->assertTrue($first->equalTo($shopper->refresh()->disabled_at));
    }

    public function test_activate_clears_disabled_at(): void
    {
        $shopper = User::factory()->disabled()->create();

        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/activate")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.disabled_at', null);

        $this->assertNull($shopper->refresh()->disabled_at);

        // Idempotent on an already-active shopper.
        $this->asAdmin()->postJson("/api/v1/admin/users/{$shopper->id}/activate")
            ->assertOk()->assertJsonPath('user.status', 'active');
    }
}
