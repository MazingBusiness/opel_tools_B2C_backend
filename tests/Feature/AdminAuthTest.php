<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\Admin;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_login_returns_admin_token(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.is_super', true);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'opel-b2c-admin',
        ]);
    }

    public function test_non_staff_with_valid_password_gets_generic_401(): void
    {
        User::factory()->create([
            'email' => 'shopper@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'shopper@example.com',
            'password' => 'password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonMissingPath('token');
    }

    public function test_bad_password_gets_generic_401(): void
    {
        Admin::factory()->super()->create([
            'email' => 'admin@example.com',
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_shopper_token_on_admin_me_returns_403(): void
    {
        $user = User::factory()->create([
            'email' => 'shopper@example.com',
        ]);

        $token = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
    }

    public function test_staff_with_shopper_star_token_on_admin_me_returns_403(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
        ]);

        // Default Sanctum abilities are ["*"] (shopper OTP/Google). Must not unlock admin.
        $token = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertForbidden();
    }

    public function test_admin_me_and_logout(): void
    {
        $user = Admin::factory()->super()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
        ]);

        $token = $user->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.is_super', true);

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
    }
}
