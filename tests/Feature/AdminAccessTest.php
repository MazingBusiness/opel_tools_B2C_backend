<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\Admin;
use App\Modules\Admin\Models\AdminAuditLog;
use App\Modules\Auth\Models\User;
use Database\Seeders\AdminStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private Admin $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = Admin::factory()->super()->create(['email' => 'boss@example.com']);
    }

    private function as(Admin|string $who): static
    {
        $this->app['auth']->forgetGuards();
        $token = is_string($who) ? $who : $who->createToken(Admin::TOKEN_NAME, ['admin'])->plainTextToken;

        return $this->withToken($token);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ravi Ops',
            'email' => 'Ravi@Example.com ',
            'phone' => '+91 98765 43210',
            'modules' => ['orders', 'fulfilment'],
            'branches' => ['MUMBAI', 'KOLKATA'],
        ], $overrides);
    }

    // ---- every admin route is guarded ----------------------------------------

    public function test_every_admin_route_has_admin_and_a_module_or_super_guard(): void
    {
        $selfService = ['api/v1/admin/auth/me', 'api/v1/admin/auth/logout', 'api/v1/admin/auth/password', 'api/v1/admin/auth/profile'];
        $unguarded = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/admin') || $uri === 'api/v1/admin/auth/login') {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $hasAdmin = in_array('admin', $mw, true);
            $hasScope = in_array('admin.super', $mw, true)
                || collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'admin.module:'))
                || in_array($uri, $selfService, true);
            if (! $hasAdmin || ! $hasScope) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $unguarded, 'Admin routes missing a module or super guard.');
    }

    // ---- modules ---------------------------------------------------------------

    public function test_module_guard_blocks_sections_not_assigned(): void
    {
        $sub = Admin::factory()->limited(['catalog'], ['KOLKATA'])->create();

        $this->as($sub)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->as($sub)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->as($sub)->getJson('/api/v1/admin/home')->assertForbidden();
        $this->as($sub)->getJson('/api/v1/admin/seo/targets')->assertForbidden();
        $this->as($sub)->getJson('/api/v1/admin/admins')->assertForbidden();
        $this->as($sub)->getJson('/api/v1/admin/catalog/products')->assertOk();
        $this->as($sub)->getJson('/api/v1/admin/auth/me')->assertOk()
            ->assertJsonPath('user.is_super', false)
            ->assertJsonPath('user.modules', ['catalog'])
            ->assertJsonPath('user.branches', ['KOLKATA']);
    }

    // ---- managing sub-admins ----------------------------------------------------

    public function test_super_creates_sub_admin_with_one_time_temporary_password(): void
    {
        $res = $this->as($this->super)->postJson('/api/v1/admin/admins', $this->payload())
            ->assertCreated()
            ->assertJsonPath('admin.email', 'ravi@example.com')
            ->assertJsonPath('admin.phone', '+919876543210')
            ->assertJsonPath('admin.modules', ['orders', 'fulfilment'])
            ->assertJsonPath('admin.branches', ['KOLKATA', 'MUMBAI'])
            ->assertJsonPath('admin.must_change_password', true);

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $temp = $res->json('temporary_password');
        $this->assertIsString($temp);
        $this->assertSame(12, strlen($temp));

        $admin = Admin::query()->where('email', 'ravi@example.com')->firstOrFail();
        $this->assertNotSame($temp, $admin->password);
        $this->assertTrue(Hash::check($temp, $admin->password));
        $this->assertFalse($admin->is_super);
        $this->assertSame($this->super->id, $admin->created_by);

        // Never shown again.
        $this->as($this->super)->getJson("/api/v1/admin/admins/{$admin->id}")->assertOk()
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonMissingPath('admin.password')
            ->assertJsonPath('audit.0.action', 'created')
            ->assertJsonPath('audit.0.actor', $this->super->name);
        $this->as($this->super)->getJson('/api/v1/admin/admins')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_create_validates_modules_branches_phone_and_unique_email(): void
    {
        $this->as($this->super)->postJson('/api/v1/admin/admins', $this->payload([
            'email' => 'boss@example.com', 'modules' => ['payroll'], 'branches' => ['PUNE'], 'phone' => '12ab',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email', 'modules.0', 'branches.0', 'phone']);
    }

    public function test_only_super_manages_admins_and_super_is_never_a_target(): void
    {
        $sub = Admin::factory()->create();

        $this->as($sub)->postJson('/api/v1/admin/admins', $this->payload())->assertForbidden();
        $this->as($sub)->postJson("/api/v1/admin/admins/{$sub->id}/reset-password")->assertForbidden();

        foreach (['get' => '', 'put' => '', 'post' => '/reset-password'] as $method => $suffix) {
            $this->as($this->super)->json($method, "/api/v1/admin/admins/{$this->super->id}{$suffix}", $this->payload())->assertNotFound();
        }
        $this->as($this->super)->postJson("/api/v1/admin/admins/{$this->super->id}/deactivate")->assertNotFound();
        $this->assertNull($this->super->fresh()->disabled_at);
    }

    public function test_update_logs_admin_out_and_audits_changes_only(): void
    {
        $sub = Admin::factory()->limited(['orders'], ['DELHI'])->create(['name' => 'Old', 'email' => 'old@example.com', 'phone' => null]);
        $token = $sub->createToken(Admin::TOKEN_NAME, ['admin']);

        $this->as($this->super)->putJson("/api/v1/admin/admins/{$sub->id}", [
            'name' => 'Old', 'email' => 'old@example.com', 'phone' => null, 'modules' => ['orders', 'users'], 'branches' => ['DELHI'],
        ])->assertOk()->assertJsonPath('admin.modules', ['orders', 'users']);

        $this->assertNull(PersonalAccessToken::find($token->accessToken->id));
        $log = AdminAuditLog::query()->where('admin_id', $sub->id)->sole();
        $this->assertSame('updated', $log->action);
        $this->assertSame($this->super->id, $log->actor_id);
        $this->assertSame(['before' => ['modules' => ['orders']], 'after' => ['modules' => ['orders', 'users']]], $log->changes);
    }

    public function test_no_change_update_keeps_sessions_and_writes_no_audit(): void
    {
        $sub = Admin::factory()->limited(['orders'], ['DELHI'])->create(['name' => 'Same', 'email' => 'same@example.com', 'phone' => null]);
        $token = $sub->createToken(Admin::TOKEN_NAME, ['admin']);

        $this->as($this->super)->putJson("/api/v1/admin/admins/{$sub->id}", [
            'name' => 'Same', 'email' => 'same@example.com', 'phone' => null, 'modules' => ['orders'], 'branches' => ['DELHI'],
        ])->assertOk();

        $this->assertNotNull(PersonalAccessToken::find($token->accessToken->id));
        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_reset_password_revokes_sessions_forces_change_and_never_audits_the_password(): void
    {
        $sub = Admin::factory()->create();
        $token = $sub->createToken(Admin::TOKEN_NAME, ['admin']);

        $res = $this->as($this->super)->postJson("/api/v1/admin/admins/{$sub->id}/reset-password")->assertOk()
            ->assertJsonPath('admin.must_change_password', true);
        $temp = $res->json('temporary_password');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->assertNull(PersonalAccessToken::find($token->accessToken->id));
        $this->assertTrue(Hash::check($temp, $sub->fresh()->password));
        $log = AdminAuditLog::query()->where('admin_id', $sub->id)->sole();
        $this->assertSame('password_reset', $log->action);
        $this->assertStringNotContainsString($temp, json_encode($log->toArray()));
        $this->assertStringNotContainsString($sub->fresh()->password, json_encode($log->toArray()));
    }

    public function test_deactivate_blocks_login_and_activate_restores_it(): void
    {
        $sub = Admin::factory()->create(['email' => 'sub@example.com']);
        $token = $sub->createToken(Admin::TOKEN_NAME, ['admin']);

        $this->as($this->super)->postJson("/api/v1/admin/admins/{$sub->id}/deactivate")->assertOk()->assertJsonPath('admin.disabled', true);
        $this->as($this->super)->postJson("/api/v1/admin/admins/{$sub->id}/deactivate")->assertOk();
        $this->assertNull(PersonalAccessToken::find($token->accessToken->id));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'sub@example.com', 'password' => 'password'])
            ->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');

        $this->as($this->super)->postJson("/api/v1/admin/admins/{$sub->id}/activate")->assertOk()->assertJsonPath('admin.disabled', false);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'sub@example.com', 'password' => 'password'])->assertOk();

        $this->assertSame(['deactivated', 'activated'], AdminAuditLog::query()->where('admin_id', $sub->id)->orderBy('id')->pluck('action')->all());
    }

    // ---- first login: forced password change -----------------------------------

    public function test_temporary_password_must_be_changed_before_anything_else(): void
    {
        $temp = $this->as($this->super)->postJson('/api/v1/admin/admins', $this->payload())->json('temporary_password');

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/admin/auth/login', ['email' => 'ravi@example.com', 'password' => $temp])->assertOk()
            ->assertJsonPath('user.must_change_password', true);
        $token = $login->json('token');

        $this->as($token)->getJson('/api/v1/admin/orders')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->patchJson('/api/v1/admin/auth/profile', ['name' => 'X'])->assertForbidden();
        $this->as($token)->getJson('/api/v1/admin/auth/me')->assertOk();

        $this->as($token)->putJson('/api/v1/admin/auth/password', [
            'current_password' => $temp, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->as($token)->getJson('/api/v1/admin/orders')->assertOk();
        $this->assertNotNull(Admin::query()->where('email', 'ravi@example.com')->value('last_login_at'));
    }

    // ---- token isolation -------------------------------------------------------

    public function test_admin_token_does_not_work_on_shopper_routes(): void
    {
        $this->as($this->super)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_shopper_with_admin_ability_token_cannot_reach_admin_routes(): void
    {
        $shopper = User::factory()->create();
        $token = $shopper->createToken(Admin::TOKEN_NAME, ['admin'])->plainTextToken;

        $this->as($token)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->as($token)->getJson('/api/v1/admin/orders')->assertUnauthorized();
    }

    public function test_forgot_and_reset_password_routes_are_gone(): void
    {
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'boss@example.com'])->assertNotFound();
        $this->postJson('/api/v1/admin/auth/reset-password', [])->assertNotFound();
    }

    // ---- seeder and console recovery -------------------------------------------

    public function test_seeder_never_overwrites_an_existing_super_admin(): void
    {
        $hash = $this->super->password;
        $this->seed(AdminStaffSeeder::class);

        $this->assertSame(1, Admin::query()->count());
        $this->assertSame($hash, $this->super->fresh()->password);
    }

    public function test_seeder_creates_super_admin_when_none_exists(): void
    {
        $this->super->delete();
        $this->assertNotEmpty(env('ADMIN_SEED_EMAIL'));
        $this->seed(AdminStaffSeeder::class);

        $owner = Admin::query()->sole();
        $this->assertTrue($owner->is_super);
        $this->assertSame(strtolower(trim((string) env('ADMIN_SEED_EMAIL'))), $owner->email);
        $this->assertTrue(Hash::check((string) env('ADMIN_SEED_PASSWORD'), $owner->password));
    }

    public function test_console_command_resets_super_password_and_logs_out(): void
    {
        $token = $this->super->createToken(Admin::TOKEN_NAME, ['admin']);
        $this->super->forceFill(['disabled_at' => now()])->save();

        $this->artisan('admin:reset-super-password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'Recover123')
            ->expectsQuestion('Repeat new password', 'Recover123')
            ->assertSuccessful();

        $fresh = $this->super->fresh();
        $this->assertTrue(Hash::check('Recover123', $fresh->password));
        $this->assertNull($fresh->disabled_at);
        $this->assertNull(PersonalAccessToken::find($token->accessToken->id));
        $this->assertSame(['via' => 'console'], AdminAuditLog::query()->sole()->changes);
    }

    public function test_console_command_rejects_weak_or_mismatched_password_and_sub_admins(): void
    {
        $hash = $this->super->password;
        $sub = Admin::factory()->create(['email' => 'sub@example.com']);

        $this->artisan('admin:reset-super-password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'weak')
            ->expectsQuestion('Repeat new password', 'weak')
            ->assertFailed();
        $this->artisan('admin:reset-super-password', ['email' => 'boss@example.com'])
            ->expectsQuestion('New password', 'Recover123')
            ->expectsQuestion('Repeat new password', 'Recover124')
            ->assertFailed();
        $this->artisan('admin:reset-super-password', ['email' => 'sub@example.com'])->assertFailed();

        $this->assertSame($hash, $this->super->fresh()->password);
        $this->assertSame(0, AdminAuditLog::query()->count());
    }
}
