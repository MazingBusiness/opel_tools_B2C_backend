<?php

namespace App\Modules\Wishlist\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class WishlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('wishlist', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
