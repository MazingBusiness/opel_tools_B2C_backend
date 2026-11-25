<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\Admin;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopper_token_forbidden_on_profile_and_password(): void
    {
        $user = User::factory()->create([
            'email' => 'shopper@example.com',
        ]);

        $token = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/admin/auth/profile', ['name' => 'Nope'])
            ->assertUnauthorized();

        $this->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertUnauthorized();
    }

    public function test_admin_can_update_name_email_locked(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
            'name' => 'Old Name',
        ]);

        $token = $user->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/admin/auth/profile', [
                'name' => '  New Admin  ',
                'email' => 'hacker@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.name', 'New Admin')
            ->assertJsonPath('user.email', 'admin@example.com');

        $this->assertDatabaseHas('admins', [
            'id' => $user->id,
            'name' => 'New Admin',
            'email' => 'admin@example.com',
        ]);
    }

    public function test_wrong_current_password_returns_422(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
        ]);

        $token = $user->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_password_change_revokes_other_admin_tokens_keeps_current(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
        ]);

        $other = $user->createToken('opel-b2c-admin', ['admin']);
        $current = $user->createToken('opel-b2c-admin', ['admin']);

        $this->withToken($current->plainTextToken)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $other->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $current->accessToken->id,
        ]);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123', $user->password));

        // Assert revoked token first (fresh request). Guard state can linger across
        // consecutive authenticated calls in the same test process.
        $this->app['auth']->forgetGuards();

        $this->withToken($other->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk();
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
            'password' => 'OldPass123',
        ]);

        $token = $user->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'OldPass123',
                'password' => 'OldPass123',
                'password_confirmation' => 'OldPass123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }
}
