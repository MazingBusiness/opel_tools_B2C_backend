<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopper_token_forbidden_on_profile_and_password(): void
    {
        $user = User::factory()->create([
            'email' => 'shopper@example.com',
            'is_staff' => false,
        ]);

        $token = $user->createToken('opel-b2c')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/admin/auth/profile', ['name' => 'Nope'])
            ->assertForbidden();

        $this->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_name_email_locked(): void
    {
        $user = User::factory()->staff()->create([
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

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Admin',
            'email' => 'admin@example.com',
        ]);
    }

    public function test_wrong_current_password_returns_422(): void
    {
        $user = User::factory()->staff()->create([
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
        $user = User::factory()->staff()->create([
            'email' => 'admin@example.com',
        ]);

        $other = $user->createToken('opel-b2c-admin', ['admin']);
        $current = $user->createToken('opel-b2c-admin', ['admin']);
        $shopper = $user->createToken('opel-b2c');

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
        // Only other tokens named opel-b2c-admin are revoked
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $shopper->accessToken->id,
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

    public function test_forgot_password_non_staff_returns_200_sends_nothing(): void
    {
        Notification::fake();

        User::factory()->create([
            'email' => 'shopper@example.com',
            'password' => 'password',
            'is_staff' => false,
        ]);

        $this->postJson('/api/v1/admin/auth/forgot-password', [
            'email' => 'shopper@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_unknown_email_returns_200_sends_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/admin/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Notification::assertNothingSent();
    }

    public function test_forgot_and_reset_password_for_staff_revokes_all_tokens(): void
    {
        Notification::fake();

        $user = User::factory()->staff()->create([
            'email' => 'admin@example.com',
        ]);

        $tokenA = $user->createToken('opel-b2c-admin', ['admin']);
        $tokenB = $user->createToken('opel-b2c');

        $this->postJson('/api/v1/admin/auth/forgot-password', [
            'email' => 'Admin@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$resetToken) {
            $resetToken = $notification->token;

            return true;
        });

        $this->assertNotEmpty($resetToken);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => 'admin@example.com',
            'token' => $resetToken,
            'password' => 'ResetPass123',
            'password_confirmation' => 'ResetPass123',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $user->refresh();
        $this->assertTrue(Hash::check('ResetPass123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->withToken($tokenA->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();

        $this->withToken($tokenB->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
    }

    public function test_reset_password_url_uses_raw_ampersand_and_single_encoded_email(): void
    {
        Notification::fake();
        config(['app.admin_frontend_url' => 'http://localhost:5174/']);

        $user = User::factory()->staff()->create([
            'email' => 'admin+ops@opel.local',
        ]);

        $this->postJson('/api/v1/admin/auth/forgot-password', [
            'email' => 'admin+ops@opel.local',
        ])->assertOk();

        $mail = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$mail) {
            $mail = $notification->toMail($user);

            return true;
        });

        $url = $mail->actionUrl;

        $this->assertStringStartsWith('http://localhost:5174/reset-password?token=', $url);
        $this->assertStringContainsString('&email=admin%2Bops%40opel.local', $url);
        $this->assertStringNotContainsString('&amp;', $url);
        $this->assertStringNotContainsString('%2540', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['token', 'email'], array_keys($query));
        $this->assertSame('admin+ops@opel.local', $query['email']);

        // Rendered text part must carry the raw URL; HTML part may entity-encode
        // it in attributes, but it must decode back to the exact same URL.
        $text = (string) app(\Illuminate\Mail\Markdown::class)->renderText($mail->markdown, $mail->data());
        $this->assertStringContainsString($url, $text);
        $this->assertStringNotContainsString('&amp;email=', $text);

        $html = (string) $mail->render();
        $this->assertMatchesRegularExpression('/href="([^"]*reset-password[^"]*)"/', $html);
        preg_match('/href="([^"]*reset-password[^"]*)"/', $html, $m);
        $this->assertSame($url, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    public function test_reset_password_invalid_token_returns_generic_422(): void
    {
        User::factory()->staff()->create([
            'email' => 'admin@example.com',
        ]);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => 'admin@example.com',
            'token' => 'invalid-token',
            'password' => 'ResetPass123',
            'password_confirmation' => 'ResetPass123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to reset password.');
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = User::factory()->staff()->create([
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
