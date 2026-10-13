<?php

namespace App\Modules\Seo\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Named limiter used by GET /api/v1/seo (throttle:seo).
        RateLimiter::for('seo', function (Request $request): Limit {
            if (self::hasValidEdgeKey($request)) {
                return Limit::perMinute(max(1, (int) config('seo.edge_rate_limit', 6000)))->by('seo-edge');
            }

            return Limit::perMinute(max(1, (int) config('seo.rate_limit', 300)))->by('seo-ip:'.$request->ip());
        });
    }

    public static function hasValidEdgeKey(Request $request): bool
    {
        $expected = config('seo.edge_key');
        $given = $request->header('X-Seo-Edge-Key');

        return is_string($expected) && $expected !== ''
            && is_string($given) && $given !== ''
            && hash_equals($expected, $given);
    }
}
