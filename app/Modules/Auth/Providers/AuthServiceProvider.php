<?php

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Services\KreaitFirebaseIdTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FirebaseIdTokenVerifier::class, KreaitFirebaseIdTokenVerifier::class);
    }

    public function boot(): void
    {
        RateLimiter::for('otp', function (Request $request) {
            $identifier = strtolower((string) $request->input('identifier'));

            return Limit::perMinute(5)->by($request->ip().'|'.$identifier);
        });

        RateLimiter::for('google', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
