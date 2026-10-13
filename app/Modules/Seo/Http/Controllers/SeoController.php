<?php

namespace App\Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Seo\Services\SeoResolver;
use App\Modules\Seo\Support\SeoCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Public, read-only SEO endpoints. No auth, no internal fields
 * (keywords and raw JSON-LD overrides are never returned).
 */
class SeoController extends Controller
{
    public function __construct(private SeoResolver $seo)
    {
    }

    /**
     * GET /api/v1/seo?path=/products/12
     * 200 for indexable/known pages and private routes (forced noindex),
     * 404 (same JSON shape, noindex) for unknown/unpublished targets.
     */
    public function show(Request $request): JsonResponse
    {
        $raw = $request->query('path', '/');
        $raw = is_string($raw) ? $raw : '/';

        // Resolve to a target first (cheap id lookups); the cache is keyed on the
        // RESOLVED target, so URL variants can't create new entries. Unknown
        // paths are never cached; all private routes share one entry.
        $target = $this->seo->resolveTarget($raw);
        $build = fn (): array => $this->seo->payload($this->seo->contextFor($target));
        $ttl = max(0, (int) config('seo.cache_ttl', 300));
        $cacheKey = $target['type'] === 'not_found' ? null : SeoCache::targetKey($target['type'], $target['key']);

        $payload = $cacheKey !== null && $ttl > 0 ? Cache::get($cacheKey) : null;
        if (! is_array($payload)) {
            $payload = $build();
            if ($cacheKey !== null && $ttl > 0 && $payload['status'] === 200) {
                Cache::put($cacheKey, $payload, $ttl);
            }
        }

        return response()
            ->json($payload, $payload['status'] === 200 ? 200 : 404)
            ->header('Cache-Control', 'public, max-age='.min(300, $ttl ?: 60))
            ->header('X-Robots-Tag', 'noindex');
    }

    public function sitemap(): Response
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $max = max(1, (int) config('seo.sitemap_max_urls', 50000));
        $count = 0;
        foreach ($this->seo->sitemapEntries() as $entry) {
            if (++$count > $max) {
                // Protocol limit per file; split into a sitemap index when this fires.
                Log::warning('sitemap.xml truncated at '.$max.' URLs; add a sitemap index.');
                break;
            }
            $xml->startElement('url');
            $xml->writeElement('loc', $entry['loc']);
            if ($entry['lastmod'] !== null) {
                $xml->writeElement('lastmod', $entry['lastmod']->toAtomString());
            }
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function robots(): Response
    {
        if (! config('seo.allow_indexing', true)) {
            // Non-production: block all crawling, no sitemap advertised.
            return response("User-agent: *\nDisallow: /\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        $lines = ['User-agent: *', 'Allow: /'];
        foreach ((array) config('seo.private_prefixes', []) as $prefix) {
            $lines[] = 'Disallow: '.$prefix;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->seo->storefrontUrl('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
