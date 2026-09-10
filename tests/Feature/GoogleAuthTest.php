<?php

namespace Tests\Feature;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\FirebaseIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIdentity(
        string $uid = 'google-uid-1',
        string $email = 'shopper@gmail.com',
        ?string $name = 'Google Shopper',
    ): FirebaseIdentity {
        return new FirebaseIdentity(
            uid: $uid,
            email: $email,
            emailVerified: true,
            name: $name,
            avatar: 'https://lh3.googleusercontent.com/a/photo',
        );
    }

    private function fakeVerifier(FirebaseIdentity $identity): void
    {
        config(['services.firebase.credentials' => 'testing']);

        $this->app->instance(FirebaseIdTokenVerifier::class, new class($identity) implements FirebaseIdTokenVerifier
        {
            public function __construct(private FirebaseIdentity $identity) {}

            public function verify(string $idToken): FirebaseIdentity
            {
                return $this->identity;
            }
        });
    }

    public function test_google_login_creates_user_and_token(): void
    {
        $this->fakeVerifier($this->fakeIdentity());

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('profile_complete', true)
            ->assertJsonPath('user.email', 'shopper@gmail.com')
            ->assertJsonPath('user.name', 'Google Shopper');

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', [
            'email' => 'shopper@gmail.com',
            'firebase_uid' => 'google-uid-1',
        ]);
    }

    public function test_google_login_links_existing_email_user(): void
    {
        $existing = User::factory()->incompleteProfile()->create([
            'email' => 'shopper@gmail.com',
            'firebase_uid' => null,
            'name' => null,
        ]);

        $this->fakeVerifier($this->fakeIdentity());

        $this->postJson('/api/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id)
            ->assertJsonPath('user.name', 'Google Shopper');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('google-uid-1', $existing->refresh()->firebase_uid);
    }

    public function test_google_login_rejects_invalid_token(): void
    {
        config(['services.firebase.credentials' => 'testing']);

        $this->app->instance(FirebaseIdTokenVerifier::class, new class implements FirebaseIdTokenVerifier
        {
            public function verify(string $idToken): FirebaseIdentity
            {
                throw ValidationException::withMessages([
                    'id_token' => 'That Google sign-in is invalid or has expired.',
                ]);
            }
        });

        $this->postJson('/api/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');
    }

    public function test_google_login_returns_service_unavailable_when_unconfigured(): void
    {
        config(['services.firebase.credentials' => null]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ])->assertStatus(503);
    }
}
