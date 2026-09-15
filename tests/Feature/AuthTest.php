<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\OtpChallenge;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\EmailOtpNotification;
use App\Modules\Auth\Services\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
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

    public function test_request_otp_email_uses_mail_channel(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'shopper@example.com',
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'channel' => 'email',
                'expires_in' => 120,
            ]);

        Notification::assertSentOnDemand(EmailOtpNotification::class);
        $this->assertDatabaseHas('otp_challenges', [
            'identifier' => 'shopper@example.com',
            'channel' => 'email',
        ]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_request_otp_phone_posts_to_smsalert_when_configured(): void
    {
        config([
            'services.smsalert.api_key' => 'test-key',
            'services.smsalert.sender' => 'OPELXX',
            'services.smsalert.otp_text' => 'Your OPEL login code is {code}. It expires in 2 minutes.',
        ]);

        Http::fake([
            'www.smsalert.co.in/*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => '9876543210',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'smsalert.co.in/api/push.json')
                && $request['mobileno'] === '919876543210'
                && $request['sender'] === 'OPELXX'
                && str_contains((string) $request['text'], '123456');
        });
    }

    public function test_request_otp_phone_uses_sms_channel(): void
    {
        Http::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => '9876543210',
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'channel' => 'sms',
            ]);

        $this->assertDatabaseHas('otp_challenges', [
            'identifier' => '919876543210',
            'channel' => 'sms',
        ]);
        Http::assertNothingSent();
    }

    public function test_verify_creates_user_and_token_for_new_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'new@example.com',
        ])->assertOk();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'new@example.com',
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('profile_complete', false)
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.name', null);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_verify_logs_in_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'existing@example.com',
        ])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'existing@example.com',
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_bad_otp_returns_unprocessable(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'shopper@example.com',
        ])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'shopper@example.com',
            'code' => '000000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_otp_returns_unprocessable(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'shopper@example.com',
        ])->assertOk();

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'shopper@example.com',
            'code' => '123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_profile_update_marks_profile_complete(): void
    {
        $user = User::factory()->incompleteProfile()->create([
            'email' => 'shopper@example.com',
            'phone' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('profile_complete', false);

        $this->patchJson('/api/v1/auth/profile', [
            'name' => 'Alok Shopper',
            'phone' => '9876543210',
            'avatar' => 'https://cdn.example.com/a.jpg',
        ])
            ->assertOk()
            ->assertJsonPath('profile_complete', true)
            ->assertJsonPath('user.name', 'Alok Shopper')
            ->assertJsonPath('user.phone', '919876543210')
            ->assertJsonPath('user.avatar', 'https://cdn.example.com/a.jpg');
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_otp_code_is_hashed_at_rest(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'shopper@example.com',
        ])->assertOk();

        $challenge = OtpChallenge::query()->first();

        $this->assertNotSame('123456', $challenge->code_hash);
        $this->assertTrue(Hash::check('123456', $challenge->code_hash));
    }
}
