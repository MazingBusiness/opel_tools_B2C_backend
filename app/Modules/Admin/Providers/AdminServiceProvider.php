<?php

namespace App\Modules\Admin\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('admin-password-reset', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('admin-password-change', function (Request $request) {
            $id = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(5)->by((string) $id);
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $base = rtrim((string) config('app.admin_frontend_url', 'http://localhost:5174'), '/');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : (string) ($notifiable->email ?? '');

            // Explicit '&' separator: http_build_query() otherwise honours the
            // arg_separator.output ini (some setups use "&amp;"). The URL must be
            // raw here; Blade escapes it to &amp; only inside the HTML mail part,
            // which mail clients decode. Copy links from the text/plain part when
            // reading MAIL_MAILER=log output.
            return $base.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $email,
            ], '', '&', PHP_QUERY_RFC3986);
        });
    }
}
