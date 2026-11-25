<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Console\ResetSuperAdminPasswordCommand;
use App\Modules\Admin\Models\Admin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ResetSuperAdminPasswordCommand::class]);
        }

        // Admin tokens work only on /admin routes and shopper tokens never do, so
        // an admin id can never be read as a shopper id (or the reverse).
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }
            $isAdminToken = $token->tokenable_type === (new Admin)->getMorphClass();

            return $isAdminToken === request()->is('api/v1/admin/*');
        });

        // Admin catalog overrides: "" is a deliberate override (null = reset to B2B),
        // so keep empty strings on these routes.
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request): bool => $request->is('api/v1/admin/catalog/*'));

        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$email),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('admin-password-change', function (Request $request) {
            $id = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(5)->by((string) $id);
        });
    }
}
