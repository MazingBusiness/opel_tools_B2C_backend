<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Seo\Support\SeoCache;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side catalog cache version. Every admin catalog save / reset bumps it:
 * derived caches (hidden-id sets) are keyed by it and the SEO payload cache is
 * bumped too, because SEO payloads embed names, prices and availability.
 *
 * Note: public catalog responses also carry Cache-Control: public, max-age=60
 * (CacheCatalogResponse). Browsers / CDNs may hold those for up to 60s; that
 * cannot be purged from here.
 */
final class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function key(string $name): string
    {
        return 'catalog:v'.self::version().':'.$name;
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
        SeoCache::bump();
    }
}
