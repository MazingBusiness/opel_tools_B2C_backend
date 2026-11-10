<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class B2bUserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_missing_identifier_or_invalid_phone(): void
    {
        $this->postRegistration([])->assertUnprocessable();
        $this->postRegistration(['name' => 'Asha'])->assertUnprocessable();
        $this->postRegistration(['phone' => '12345'])->assertUnprocessable();

        $this->assertSame(0, User::query()->count());
    }

    public function test_creates_shopper_without_a_login_token(): void
    {
        $response = $this->postRegistration([
            'name' => 'Asha',
            'email' => 'Asha@Example.com',
            'phone' => '98765 43210',
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('user.name', 'Asha')
            ->assertJsonPath('user.email', 'asha@example.com')
            ->assertJsonPath('user.phone', '919876543210')
            ->assertJsonPath('user.disabled', false)
            ->assertJsonMissingPath('token');

        $user = User::query()->findOrFail($response->json('user.id'));

        $this->assertNull($user->password);
        $this->assertFalse($user->is_staff);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame(0, $user->tokens()->count());

        $this->postRegistration([
            'email' => 'asha@example.com',
            'phone' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame(1, User::query()->count());
    }

    public function test_fills_only_blank_name_and_phone(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => null,
            'email' => 'asha@example.com',
            'phone' => null,
        ]);

        $this->postRegistration([
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'phone' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Asha')
            ->assertJsonPath('user.phone', '919876543210');

        $user->refresh();

        $this->assertSame('Asha', $user->name);
        $this->assertSame('919876543210', $user->phone);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame(1, User::query()->count());
    }

    public function test_does_not_replace_an_existing_email_or_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Asha',
            'email' => 'kept@example.com',
            'phone' => '919876543210',
        ]);

        $this->postRegistration([
            'name' => 'Other',
            'email' => 'other@example.com',
            'phone' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Asha')
            ->assertJsonPath('user.email', 'kept@example.com');

        $user->refresh();

        $this->assertSame('Asha', $user->name);
        $this->assertSame('kept@example.com', $user->email);
        $this->assertSame('919876543210', $user->phone);
    }

    public function test_rejects_when_email_and_phone_belong_to_different_shoppers(): void
    {
        User::factory()->create([
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'phone' => '919111111111',
        ]);
        User::factory()->create([
            'name' => 'Ravi',
            'email' => 'ravi@example.com',
            'phone' => '919222222222',
        ]);

        $this->postRegistration([
            'email' => 'asha@example.com',
            'phone' => '9222222222',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Email and phone belong to different shoppers.');

        $this->assertSame(2, User::query()->count());
        $this->assertSame('919111111111', User::query()->where('email', 'asha@example.com')->value('phone'));
        $this->assertSame('Ravi', User::query()->where('email', 'ravi@example.com')->value('name'));
    }

    public function test_rejects_staff_account(): void
    {
        $staff = User::factory()->staff()->create([
            'name' => 'Admin',
            'email' => 'staff@example.com',
            'phone' => null,
        ]);

        $this->postRegistration([
            'name' => 'Shopper',
            'email' => 'staff@example.com',
            'phone' => '9876543210',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That account cannot be registered from B2B.');

        $staff->refresh();

        $this->assertSame('Admin', $staff->name);
        $this->assertNull($staff->phone);
        $this->assertTrue($staff->is_staff);
        $this->assertSame(1, User::query()->count());
    }

    public function test_returns_disabled_shopper_unchanged(): void
    {
        $user = User::factory()->disabled()->create([
            'name' => null,
            'email' => 'off@example.com',
            'phone' => null,
        ]);
        $disabledAt = $user->disabled_at;

        $this->postRegistration([
            'name' => 'Asha',
            'email' => 'off@example.com',
            'phone' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.disabled', true)
            ->assertJsonPath('user.name', null)
            ->assertJsonPath('user.phone', null);

        $user->refresh();

        $this->assertNull($user->name);
        $this->assertNull($user->phone);
        $this->assertTrue($user->disabled_at?->equalTo($disabledAt));
        $this->assertSame(1, User::query()->count());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postRegistration(array $body): TestResponse
    {
        return $this->postJson('/api/v1/integrations/b2b/users', $body);
    }
}
