<?php

namespace App\Modules\Seo\Support;

use Illuminate\Support\Facades\Cache;

/** Versioned cache for public SEO payloads; admin saves bump the version. */
final class SeoCache
{
    private const VERSION_KEY = 'seo:version';

    public const PREFIX = 'seo:payload:';

    /**
     * One entry per resolved target (e.g. product:12, page:about, private:private).
     * Old versions are never read again and expire via the TTL (no pruning job needed).
     */
    public static function targetKey(string $type, string $key): string
    {
        return self::PREFIX.'v'.self::version().':'.$type.':'.$key;
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }
}
