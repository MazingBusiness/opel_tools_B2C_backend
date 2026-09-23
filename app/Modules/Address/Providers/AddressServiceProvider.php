<?php

namespace App\Modules\Address\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AddressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('addresses', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
