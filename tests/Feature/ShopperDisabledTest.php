<?php

namespace Tests\Feature;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\OtpCodeGenerator;
use App\Modules\Auth\Support\DisabledAccount;
use App\Modules\Auth\Support\FirebaseIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ShopperDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new class extends OtpCodeGenerator
        {
            public function generate(): string
            {
                return '123456';
            }
        });
    }

    private function fakeGoogle(string $email, string $uid = 'google-uid-1'): void
    {
        config(['services.firebase.credentials' => 'testing']);

        $identity = new FirebaseIdentity(
            uid: $uid,
            email: $email,
            emailVerified: true,
            name: 'Google Shopper',
            avatar: null,
        );

        $this->app->instance(FirebaseIdTokenVerifier::class, new class($identity) implements FirebaseIdTokenVerifier
        {
            public function __construct(private FirebaseIdentity $identity) {}

            public function verify(string $idToken): FirebaseIdentity
            {
                return $this->identity;
            }
        });
    }

    private function otpVerify(string $identifier): TestResponse
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $identifier])->assertOk();

        return $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $identifier,
            'code' => '123456',
        ]);
    }

    public function test_otp_verify_refuses_disabled_user_without_issuing_token(): void
    {
        $user = User::factory()->disabled()->create(['email' => 'blocked@example.com']);

        $response = $this->otpVerify('blocked@example.com')->assertForbidden();

        $this->assertSame(DisabledAccount::body(), $response->json());
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    public function test_otp_verify_refuses_disabled_phone_user(): void
    {
        $user = User::factory()->disabled()->create(['phone' => '919876543210']);

        $response = $this->otpVerify('9876543210')->assertForbidden();

        $this->assertSame(DisabledAccount::body(), $response->json());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    public function test_google_login_refuses_disabled_user_with_identical_body(): void
    {
        $user = User::factory()->disabled()->create(['email' => 'blocked@gmail.com']);
        $this->fakeGoogle('blocked@gmail.com');

        $google = $this->postJson('/api/v1/auth/google', ['id_token' => str_repeat('a', 40)])
            ->assertForbidden();

        $this->assertSame(DisabledAccount::body(), $google->json());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());

        $otp = $this->otpVerify('blocked@gmail.com')->assertForbidden();
        $this->assertSame($google->getContent(), $otp->getContent());
    }

    public function test_reactivated_user_can_log_in_again(): void
    {
        $user = User::factory()->disabled()->create(['email' => 'back@example.com']);
        $this->otpVerify('back@example.com')->assertForbidden();

        $user->forceFill(['disabled_at' => null])->save();

        $this->otpVerify('back@example.com')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_disabled_user_with_live_token_is_blocked_on_shopper_routes(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('opel-b2c')->plainTextToken;

        // Simulate a token that survived (e.g. race) while the account is disabled.
        $user->forceFill(['disabled_at' => now()])->save();

        foreach ([
            ['GET', '/api/v1/auth/me'],
            ['PATCH', '/api/v1/auth/profile'],
            ['GET', '/api/v1/cart'],
            ['GET', '/api/v1/wishlist'],
            ['GET', '/api/v1/addresses'],
            ['GET', '/api/v1/orders'],
        ] as [$method, $uri]) {
            $this->app['auth']->forgetGuards();
            $response = $this->withToken($plain)->json($method, $uri, ['name' => 'X']);
            $response->assertForbidden();
            $this->assertSame(DisabledAccount::body(), $response->json(), "{$method} {$uri}");
        }

        $this->assertNotSame('X', $user->refresh()->name);
    }

    public function test_active_user_is_not_blocked_by_middleware(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/cart')->assertOk();
    }

    public function test_profile_patch_cannot_set_disabled_at(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($plain)->patchJson('/api/v1/auth/profile', [
            'name' => 'New Name',
            'disabled_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonMissingPath('user.disabled_at');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertNull($user->disabled_at);
    }

    public function test_disabled_at_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->fill(['name' => 'Filled', 'disabled_at' => now()])->save();

        $user->refresh();
        $this->assertSame('Filled', $user->name);
        $this->assertNull($user->disabled_at);
        $this->assertNotContains('disabled_at', $user->getFillable());
    }
}
