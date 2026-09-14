<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Services\CatalogSearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CatalogSearch::class);
    }

    public function boot(): void
    {
        RateLimiter::for('catalog', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip());
        });
    }
}
