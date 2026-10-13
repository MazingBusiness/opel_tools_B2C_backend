<?php

namespace App\Modules\Seo\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Seo\Models\SeoMeta;
use App\Modules\Seo\Support\HtmlSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves a storefront path (or admin target) to SEO data.
 *
 * Defaults (when no override): title from name, description from product
 * description (stripped/truncated), canonical = absolute storefront URL of the
 * page, og_image from thumbnail/banner, h1 from name.
 *
 * JSON-LD: auto blocks (Product+Offer, BreadcrumbList, FAQPage, and
 * WebSite+Organization on home). An admin schema_jsonld override REPLACES the
 * auto block(s) with the same @type and AUGMENTS (is appended to) the rest.
 *
 * Private routes and missing/unpublished targets are forced noindex and get
 * no JSON-LD, regardless of stored overrides.
 */
class SeoResolver
{
    public const MAX_PATH_LENGTH = 512;

    public const MAX_JSONLD_DEPTH = 32;

    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    // ---- path handling ---------------------------------------------------

    public function normalizePath(?string $path): ?string
    {
        $path = (string) $path;
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH) {
            return $path === '' ? '/' : null;
        }

        // Drop query/fragment; accept full URLs by taking their path only.
        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '/');
        }
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        $path = rawurldecode($path);

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public function isPrivate(string $path): bool
    {
        $lower = strtolower($path);
        foreach ((array) config('seo.private_prefixes', []) as $prefix) {
            $prefix = strtolower((string) $prefix);
            if ($lower === $prefix || str_starts_with($lower, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public function storefrontUrl(string $path = '/'): string
    {
        $base = rtrim((string) config('seo.storefront_url'), '/');

        return $path === '/' ? $base.'/' : $base.$path;
    }

    public function storefrontHost(): ?string
    {
        $host = parse_url((string) config('seo.storefront_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Absolute http(s) URL on exactly the storefront origin: same scheme, host
     * and port as STOREFRONT_URL (default ports normalised), and no userinfo.
     */
    public function isStorefrontUrl(?string $url): bool
    {
        $abs = $this->absoluteHttpUrl($url);
        $origin = $this->originOf((string) config('seo.storefront_url'));
        if ($abs === null || $origin === null) {
            return false;
        }
        $parts = parse_url($abs);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || str_contains((string) ($parts['host'] ?? ''), '@')) {
            return false;
        }
        // Belt and braces: any "@" before the first path/query/fragment char is userinfo.
        $authority = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $abs);
        $authority = (string) strtok($authority, '/?#');
        if (str_contains($authority, '@')) {
            return false;
        }

        return $this->originOf($abs) === $origin;
    }

    /** "scheme://host:port" (lower-cased, default port filled in), or null. */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }

    /** Stored canonical override if it points at the storefront; foreign hosts are ignored. */
    private function canonicalOverride(?SeoMeta $meta): ?string
    {
        $url = $this->absoluteHttpUrl($meta?->canonical_url);

        return $url !== null && $this->isStorefrontUrl($url) ? $url : null;
    }

    // ---- resolution ------------------------------------------------------

    /**
     * @return array<string, mixed> resolution context
     */
    public function forPath(?string $rawPath): array
    {
        return $this->contextFor($this->resolveTarget($rawPath));
    }

    /**
     * Cheap path → target resolution (ids only). Every URL variant of the same
     * page (/ABOUT, /products/0012, /category/x/y/) maps to ONE target, which
     * is what the public cache is keyed on. All private routes share one target.
     *
     * @return array{type: string, key: string, path: string}
     *   type: page|product|category_group|category|private|not_found
     */
    public function resolveTarget(?string $rawPath): array
    {
        $path = $this->normalizePath($rawPath);
        $notFound = ['type' => 'not_found', 'key' => '', 'path' => $path ?? '/'];

        if ($path === null) {
            return $notFound;
        }
        if ($this->isPrivate($path)) {
            return ['type' => 'private', 'key' => 'private', 'path' => $path];
        }

        foreach ((array) config('seo.pages', []) as $key => $page) {
            if (strtolower((string) $page['path']) === strtolower($path)) {
                return ['type' => SeoMeta::TYPE_PAGE, 'key' => (string) $key, 'path' => $path];
            }
        }

        $segments = explode('/', trim($path, '/'));
        $first = strtolower($segments[0]);

        if (count($segments) === 2 && $first === 'products') {
            // Id-only product URLs; non-numeric segments are 404.
            $id = $this->numericId($segments[1]);
            if ($id !== null && Product::query()->published()->whereKey($id)->exists()) {
                return ['type' => SeoMeta::TYPE_PRODUCT, 'key' => (string) $id, 'path' => $path];
            }

            return $notFound;
        }

        if ($first === 'category' && count($segments) === 3) {
            // Category must belong to the (active) group named in the URL.
            $id = Category::query()
                ->where('slug', $segments[2])
                ->where('is_active', true)
                ->whereHas('categoryGroup', fn ($q) => $q->where('slug', $segments[1])->where('is_active', true))
                ->value('id');

            return $id !== null
                ? ['type' => SeoMeta::TYPE_CATEGORY, 'key' => (string) $id, 'path' => $path]
                : $notFound;
        }

        if ($first === 'category' && count($segments) === 2) {
            $groupId = CategoryGroup::query()->where('slug', $segments[1])->where('is_active', true)->value('id');
            if ($groupId !== null) {
                return ['type' => SeoMeta::TYPE_CATEGORY_GROUP, 'key' => (string) $groupId, 'path' => $path];
            }
            // /category/{categorySlug} also resolves; canonical points to the full path.
            $id = Category::query()
                ->where('slug', $segments[1])
                ->where('is_active', true)
                ->whereHas('categoryGroup', fn ($q) => $q->where('is_active', true))
                ->value('id');

            return $id !== null
                ? ['type' => SeoMeta::TYPE_CATEGORY, 'key' => (string) $id, 'path' => $path]
                : $notFound;
        }

        return $notFound;
    }

    /**
     * @param  array{type: string, key: string, path: string}  $target
     * @return array<string, mixed>
     */
    public function contextFor(array $target): array
    {
        return match ($target['type']) {
            'private' => $this->privateContext(),
            'not_found' => $this->notFound($target['path']),
            default => $this->forTarget($target['type'], $target['key']) ?? $this->notFound($target['path']),
        };
    }

    private function numericId(string $segment): ?int
    {
        if (! ctype_digit($segment) || strlen($segment) > 18) {
            return null;
        }
        $id = (int) $segment;

        return $id > 0 ? $id : null;
    }

    /**
     * Admin lookup by (type, key). Admin may edit unpublished/inactive targets;
     * public visibility is still enforced by forPath()/sitemap.
     *
     * @return array<string, mixed>|null
     */
    public function forTarget(string $type, string $key): ?array
    {
        return match ($type) {
            SeoMeta::TYPE_PAGE => array_key_exists($key, (array) config('seo.pages', []))
                ? $this->pageContext($key)
                : null,
            SeoMeta::TYPE_PRODUCT => ($p = $this->findProductById($key, false)) ? $this->productContext($p) : null,
            SeoMeta::TYPE_CATEGORY_GROUP => ($g = $this->findById(CategoryGroup::class, $key)) ? $this->groupContext($g) : null,
            SeoMeta::TYPE_CATEGORY => ($c = $this->findById(Category::class, $key)) ? $this->categoryContext($c) : null,
            default => null,
        };
    }

    private function categoryIsPublic(Category $category): bool
    {
        return (bool) $category->is_active && (bool) ($category->categoryGroup?->is_active ?? false);
    }

    private function findProductById(string $id, bool $publishedOnly): ?Product
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            return null;
        }
        $query = Product::query();
        if ($publishedOnly) {
            $query->published();
        }

        return $query->whereKey((int) $id)->first()?->load(['categoryGroup', 'category.parent', 'brand', 'variants']);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function findById(string $class, string $id)
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            return null;
        }

        return $class::query()->whereKey((int) $id)->first();
    }

    // ---- contexts --------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function base(string $type, string $key, string $path, string $name): array
    {
        return [
            'status' => 200,
            'type' => $type,
            'key' => $key,
            'path' => $path,
            'name' => $name,
            'public' => true,
            'private' => false,
            'meta' => null,
            'defaults' => ['title' => null, 'description' => null, 'h1' => $name, 'og_image' => null],
            'breadcrumbs' => [],
            'images' => [],
            'product' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageContext(string $key): array
    {
        $page = (array) config("seo.pages.{$key}");
        $path = (string) $page['path'];
        $ctx = $this->base(SeoMeta::TYPE_PAGE, $key, $path, (string) $page['name']);
        $ctx['meta'] = SeoMeta::for(SeoMeta::TYPE_PAGE, $key);
        $ctx['defaults'] = [
            'title' => $key === 'home' ? (string) $page['title'] : $page['title'].config('seo.title_suffix'),
            'description' => (string) ($page['description'] ?? config('seo.default_description')),
            'h1' => (string) $page['name'],
            'og_image' => null,
        ];
        $ctx['breadcrumbs'] = $key === 'home'
            ? [['label' => 'Home', 'href' => '/']]
            : [['label' => 'Home', 'href' => '/'], ['label' => (string) $page['name'], 'href' => $path]];
        $ctx['updated_at'] = $ctx['meta']?->updated_at;

        return $ctx;
    }

    /**
     * @return array<string, mixed>
     */
    private function productContext(Product $product): array
    {
        $product->loadMissing(['categoryGroup', 'category.parent', 'brand', 'variants']);
        $path = '/products/'.$product->id;
        $ctx = $this->base(SeoMeta::TYPE_PRODUCT, (string) $product->id, $path, (string) $product->name);
        $ctx['public'] = (bool) $product->published && (bool) $product->approved;
        $ctx['meta'] = SeoMeta::for(SeoMeta::TYPE_PRODUCT, $product->id);
        $ctx['product'] = $product;
        $ctx['images'] = $this->productImages($product);

        $description = $this->plainText((string) $product->description, 160);
        $ctx['defaults'] = [
            'title' => $product->name.config('seo.title_suffix'),
            'description' => $description !== ''
                ? $description
                : 'Buy '.$product->name.' online at OPEL Tools. Genuine products, fast delivery across India.',
            'h1' => (string) $product->name,
            'og_image' => $ctx['images'][0] ?? null,
        ];

        $crumbs = [['label' => 'Home', 'href' => '/']];
        $group = $product->categoryGroup;
        if ($group) {
            $crumbs[] = ['label' => $this->label($group->name), 'href' => '/category/'.$group->slug];
            if ($product->category) {
                $crumbs[] = [
                    'label' => $this->label($product->category->name),
                    'href' => '/category/'.$group->slug.'/'.$product->category->slug,
                ];
            }
        }
        $crumbs[] = ['label' => (string) $product->name, 'href' => $path];
        $ctx['breadcrumbs'] = $crumbs;
        $ctx['updated_at'] = $this->latest($product->updated_at, $ctx['meta']?->updated_at);

        return $ctx;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupContext(CategoryGroup $group): array
    {
        $path = '/category/'.$group->slug;
        $name = $this->label($group->name);
        $ctx = $this->base(SeoMeta::TYPE_CATEGORY_GROUP, (string) $group->id, $path, $name);
        $ctx['public'] = (bool) $group->is_active;
        $ctx['meta'] = SeoMeta::for(SeoMeta::TYPE_CATEGORY_GROUP, $group->id);
        $ctx['defaults'] = [
            'title' => $name.config('seo.title_suffix'),
            'description' => 'Shop '.$name.' online at OPEL Tools. Genuine products, best prices and fast delivery across India.',
            'h1' => $name,
            'og_image' => null,
        ];
        $ctx['breadcrumbs'] = [['label' => 'Home', 'href' => '/'], ['label' => $name, 'href' => $path]];
        $ctx['updated_at'] = $this->latest($group->updated_at, $ctx['meta']?->updated_at);

        return $ctx;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryContext(Category $category): array
    {
        $category->loadMissing(['categoryGroup', 'parent']);
        $group = $category->categoryGroup;
        $groupSlug = $group?->slug ?? 'all';
        $path = '/category/'.$groupSlug.'/'.$category->slug;
        $name = $this->label($category->name);
        $ctx = $this->base(SeoMeta::TYPE_CATEGORY, (string) $category->id, $path, $name);
        $ctx['public'] = $this->categoryIsPublic($category);
        $ctx['meta'] = SeoMeta::for(SeoMeta::TYPE_CATEGORY, $category->id);
        $banner = $this->absoluteHttpUrl($category->banner);
        $ctx['defaults'] = [
            'title' => $name.config('seo.title_suffix'),
            'description' => 'Shop '.$name.' online at OPEL Tools. Genuine products, best prices and fast delivery across India.',
            'h1' => $name,
            'og_image' => $banner,
        ];

        $crumbs = [['label' => 'Home', 'href' => '/']];
        if ($group) {
            $crumbs[] = ['label' => $this->label($group->name), 'href' => '/category/'.$group->slug];
        }
        if ($category->parent && $category->parent->id !== $category->id) {
            $crumbs[] = [
                'label' => $this->label($category->parent->name),
                'href' => '/category/'.$groupSlug.'/'.$category->parent->slug,
            ];
        }
        $crumbs[] = ['label' => $name, 'href' => $path];
        $ctx['breadcrumbs'] = $crumbs;
        $ctx['updated_at'] = $this->latest($category->updated_at, $ctx['meta']?->updated_at);

        return $ctx;
    }

    /**
     * @return array<string, mixed>
     */
    private function privateContext(): array
    {
        // Path-independent on purpose: all private routes share one cached payload.
        $ctx = $this->base('private', 'private', '/', (string) config('seo.site_name'));
        $ctx['private'] = true;
        $ctx['public'] = false;
        $ctx['defaults']['title'] = (string) config('seo.site_name');
        $ctx['defaults']['description'] = (string) config('seo.default_description');
        $ctx['defaults']['h1'] = null;

        return $ctx;
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(string $path): array
    {
        $ctx = $this->base('not_found', $path, $path, 'Page not found');
        $ctx['status'] = 404;
        $ctx['public'] = false;
        $ctx['defaults']['title'] = 'Page not found'.config('seo.title_suffix');
        $ctx['defaults']['description'] = (string) config('seo.default_description');
        $ctx['defaults']['h1'] = null;

        return $ctx;
    }

    // ---- presentation ----------------------------------------------------

    /**
     * Public payload (no internal fields such as keywords or raw JSON-LD).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function present(array $ctx): array
    {
        /** @var SeoMeta|null $meta */
        $meta = $ctx['public'] ? $ctx['meta'] : null; // never leak overrides for hidden targets
        $defaults = $ctx['defaults'];
        $indexable = $ctx['public'] && ! $ctx['private'] && $ctx['status'] === 200;

        $title = $this->str($meta?->seo_title) ?? (string) $defaults['title'];
        $description = $this->str($meta?->meta_description) ?? (string) $defaults['description'];
        $selfUrl = $this->storefrontUrl($ctx['path']);
        // Foreign-host canonicals (legacy rows) fall back to the page's own URL.
        $canonical = $indexable ? ($this->canonicalOverride($meta) ?? $selfUrl) : null;
        if ($ctx['private'] || ! config('seo.allow_indexing', true)) {
            $robots = 'noindex,nofollow';
        } elseif (! $indexable) {
            $robots = 'noindex,follow';
        } else {
            $robots = $meta?->robots === 'noindex' ? 'noindex,follow' : 'index,follow';
        }

        $h1Override = $this->str($meta?->h1);
        $faq = $this->faq($meta?->faq);

        $ogImage = $this->absoluteHttpUrl($meta?->og_image)
            ?? $this->absoluteHttpUrl($defaults['og_image'] ?? null)
            ?? $this->absoluteHttpUrl(config('seo.default_og_image'));

        return [
            'ok' => true,
            'path' => $ctx['private'] ? null : $ctx['path'],
            'status' => $ctx['status'],
            'type' => $ctx['type'],
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
            'canonical_url' => $canonical,
            'h1' => $h1Override ?? $defaults['h1'],
            'h1_is_override' => $h1Override !== null,
            'content' => HtmlSanitizer::clean($meta?->content), // re-sanitized on output too
            'og' => [
                'title' => $this->str($meta?->og_title) ?? $title,
                'description' => $this->str($meta?->og_description) ?? $description,
                'image' => $ogImage,
                'url' => $ctx['private'] ? null : ($canonical ?? $selfUrl),
                'type' => $ctx['type'] === SeoMeta::TYPE_PRODUCT ? 'product' : 'website',
                'site_name' => (string) config('seo.site_name'),
            ],
            'breadcrumbs' => $ctx['public'] ? $ctx['breadcrumbs'] : [],
            'faq' => $faq,
            'related_products' => $meta ? $this->relatedProducts($meta->related_product_ids, $ctx) : [],
            'image_alts' => $this->imageAlts($ctx, $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function payload(array $ctx): array
    {
        $payload = $this->present($ctx);
        $payload['head_html'] = $this->headHtml($payload, $this->jsonLd($ctx, $payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $blocks
     */
    public function headHtml(array $payload, array $blocks): string
    {
        $jsonld = array_map(
            fn (array $block): string => (string) json_encode($block, self::JSON_FLAGS),
            $blocks,
        );

        return trim(view('seo.head', [
            'p' => $payload,
            'jsonld' => $jsonld,
        ])->render());
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function jsonLd(array $ctx, array $payload): array
    {
        if ($ctx['private'] || ! $ctx['public'] || $ctx['status'] !== 200) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $auto keyed by @type */
        $auto = [];

        if ($ctx['type'] === SeoMeta::TYPE_PAGE && $ctx['key'] === 'home') {
            $home = $this->storefrontUrl('/');
            $auto['WebSite'] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => (string) config('seo.site_name'),
                'url' => $home,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $this->storefrontUrl('/products').'?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ];
            $auto['Organization'] = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => (string) config('seo.site_name'),
                'url' => $home,
            ];
        }

        if ($ctx['product'] instanceof Product) {
            $auto['Product'] = $this->productLd($ctx['product'], $payload, $ctx['images']);
        }

        if (count($ctx['breadcrumbs']) >= 2) {
            $auto['BreadcrumbList'] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_map(fn (array $crumb, int $i): array => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $crumb['label'],
                    'item' => $this->storefrontUrl($crumb['href']),
                ], $ctx['breadcrumbs'], array_keys($ctx['breadcrumbs'])),
            ];
        }

        if ($payload['faq'] !== []) {
            $auto['FAQPage'] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ], $payload['faq']),
            ];
        }

        $override = self::decodeJsonLd($ctx['meta']?->schema_jsonld);
        $extra = [];
        foreach ($override as $block) {
            if (self::containsType($block, 'FAQPage')) {
                continue; // FAQPage comes only from the FAQ field
            }
            $types = (array) ($block['@type'] ?? []);
            foreach ($types as $type) {
                unset($auto[(string) $type]); // override replaces same @type
            }
            $block['@context'] ??= 'https://schema.org';
            $extra[] = $block;
        }

        return array_values(array_merge(array_values($auto), $extra));
    }

    /**
     * Decode a stored/submitted JSON-LD override into a list of objects.
     * Returns [] for null/invalid. Validation rules use validateJsonLd().
     *
     * @return list<array<string, mixed>>
     */
    public static function decodeJsonLd(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $blocks = array_is_list($decoded) ? $decoded : [$decoded];

        return array_values(array_filter($blocks, fn ($b): bool => is_array($b) && ! array_is_list($b) && isset($b['@type'])));
    }

    /**
     * @return string|null error message, or null if valid
     */
    public static function validateJsonLd(string $raw): ?string
    {
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'Schema JSON-LD must be valid JSON ('.$e->getMessage().').';
        }
        if (! is_array($decoded) || $decoded === []) {
            return 'Schema JSON-LD must be a JSON object or an array of objects.';
        }
        $blocks = array_is_list($decoded) ? $decoded : [$decoded];
        if (count($blocks) > 10) {
            return 'Schema JSON-LD may contain at most 10 objects.';
        }
        foreach ($blocks as $block) {
            if (! is_array($block) || array_is_list($block)) {
                return 'Each JSON-LD entry must be an object.';
            }
            if (! isset($block['@type']) || (! is_string($block['@type']) && ! is_array($block['@type']))) {
                return 'Each JSON-LD object needs an "@type".';
            }
            if (isset($block['@context']) && ! is_string($block['@context']) && ! is_array($block['@context'])) {
                return 'JSON-LD "@context" must be a string or object.';
            }
            if (self::depthOf($block) > self::MAX_JSONLD_DEPTH) {
                return 'Schema JSON-LD is nested too deeply (max '.self::MAX_JSONLD_DEPTH.' levels).';
            }
            if (self::containsType($block, 'FAQPage')) {
                return 'FAQPage schema is generated from the FAQ field. Remove it from Schema JSON-LD and add the questions under FAQ instead.';
            }
        }

        return null;
    }

    /**
     * True if $type appears as an @type anywhere in the structure (incl. @graph,
     * @type arrays and nested objects). Matches "FAQPage", "schema:FAQPage",
     * "https://schema.org/FAQPage", case-insensitive. Fails CLOSED (true) when
     * nesting exceeds MAX_JSONLD_DEPTH.
     *
     * @param  array<mixed>  $node
     */
    public static function containsType(array $node, string $type, int $depth = 0): bool
    {
        if ($depth > self::MAX_JSONLD_DEPTH) {
            return true; // fail closed: too deep to inspect → treat as containing the type
        }
        $needle = strtolower($type);
        foreach ((array) ($node['@type'] ?? []) as $t) {
            if (is_string($t)) {
                $t = strtolower(trim($t));
                if ($t === $needle || str_ends_with($t, ':'.$needle) || str_ends_with($t, '/'.$needle)) {
                    return true;
                }
            }
        }
        foreach ($node as $value) {
            if (is_array($value) && self::containsType($value, $type, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Nesting depth of arrays/objects (bounded; stops counting past the limit). */
    public static function depthOf(mixed $node, int $depth = 0): int
    {
        if (! is_array($node) || $depth > self::MAX_JSONLD_DEPTH) {
            return $depth;
        }
        $max = $depth + 1;
        foreach ($node as $value) {
            if (is_array($value)) {
                $max = max($max, self::depthOf($value, $depth + 1));
            }
        }

        return $max;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $images
     * @return array<string, mixed>
     */
    private function productLd(Product $product, array $payload, array $images): array
    {
        $variants = $product->variants;
        $default = $variants->firstWhere('is_default', true) ?? $variants->first();
        $inStock = $variants->contains(fn ($v): bool => (int) $v->current_stock > 0);

        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product->name,
            'description' => $payload['description'],
            'url' => $payload['canonical_url'] ?? $this->storefrontUrl('/products/'.$product->id),
        ];
        if ($images !== []) {
            $ld['image'] = $images;
        }
        if ($default?->part_no) {
            $ld['sku'] = (string) $default->part_no;
            $ld['mpn'] = (string) $default->part_no;
        }
        if ($product->brand?->name) {
            $ld['brand'] = ['@type' => 'Brand', 'name' => $this->label($product->brand->name)];
        }
        if ($product->category?->name) {
            $ld['category'] = $this->label($product->category->name);
        }

        $price = $default ? (float) $default->unit_price : 0.0;
        if ($price > 0) {
            $ld['offers'] = [
                '@type' => 'Offer',
                'priceCurrency' => (string) config('seo.currency', 'INR'),
                'price' => number_format($price, 2, '.', ''),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'url' => $ld['url'],
            ];
        }

        return $ld;
    }

    // ---- sitemap ---------------------------------------------------------

    /**
     * Indexable URLs only: static pages, active groups/categories, published+approved
     * products. Excludes targets set to noindex or canonicalised elsewhere.
     *
     * @return \Generator<int, array{loc: string, lastmod: ?Carbon}>
     */
    public function sitemapEntries(): \Generator
    {
        $metas = SeoMeta::query()
            ->get(['target_type', 'target_key', 'robots', 'canonical_url', 'updated_at'])
            ->keyBy(fn (SeoMeta $m): string => $m->target_type.':'.$m->target_key);

        $entry = function (string $type, string $key, string $path, $updatedAt) use ($metas): ?array {
            /** @var SeoMeta|null $meta */
            $meta = $metas->get($type.':'.$key);
            if ($this->isPrivate($path)) {
                return null;
            }
            $loc = $this->storefrontUrl($path);
            if ($meta?->robots === 'noindex') {
                return null;
            }
            $canonical = $this->canonicalOverride($meta);
            if ($canonical !== null && rtrim($canonical, '/') !== rtrim($loc, '/')) {
                return null;
            }

            return ['loc' => $loc, 'lastmod' => $this->latest($updatedAt, $meta?->updated_at)];
        };

        foreach ((array) config('seo.pages', []) as $key => $page) {
            if ($e = $entry(SeoMeta::TYPE_PAGE, (string) $key, (string) $page['path'], null)) {
                yield $e;
            }
        }

        if (! config('seo.sitemap_categories', false)) {
            yield from $this->productSitemapEntries($entry);

            return;
        }

        $groups = CategoryGroup::query()->where('is_active', true)->orderBy('id')->get(['id', 'slug', 'updated_at']);
        $groupSlugs = $groups->pluck('slug', 'id');
        foreach ($groups as $group) {
            if ($e = $entry(SeoMeta::TYPE_CATEGORY_GROUP, (string) $group->id, '/category/'.$group->slug, $group->updated_at)) {
                yield $e;
            }
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->whereIn('category_group_id', $groupSlugs->keys())
            ->orderBy('id')
            ->get(['id', 'slug', 'category_group_id', 'updated_at']);
        foreach ($categories as $category) {
            $path = '/category/'.$groupSlugs[$category->category_group_id].'/'.$category->slug;
            if ($e = $entry(SeoMeta::TYPE_CATEGORY, (string) $category->id, $path, $category->updated_at)) {
                yield $e;
            }
        }

        yield from $this->productSitemapEntries($entry);
    }

    /**
     * @return \Generator<int, array{loc: string, lastmod: ?Carbon}>
     */
    private function productSitemapEntries(\Closure $entry): \Generator
    {
        foreach (Product::query()->published()->orderBy('id')->select(['id', 'updated_at'])->lazyById(500) as $product) {
            if ($e = $entry(SeoMeta::TYPE_PRODUCT, (string) $product->id, '/products/'.$product->id, $product->updated_at)) {
                yield $e;
            }
        }
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @return list<string>
     */
    public function productImages(Product $product): array
    {
        $images = [];
        foreach (array_merge([$product->thumbnail_img], (array) ($product->photos ?? [])) as $url) {
            $abs = $this->absoluteHttpUrl(is_string($url) ? $url : null);
            if ($abs !== null && ! in_array($abs, $images, true)) {
                $images[] = $abs;
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    private function imageAlts(array $ctx, ?SeoMeta $meta): array
    {
        if ($ctx['type'] !== SeoMeta::TYPE_PRODUCT || $ctx['images'] === []) {
            return [];
        }
        $stored = is_array($meta?->image_alts) ? $meta->image_alts : [];
        $alts = [];
        foreach ($ctx['images'] as $i => $url) {
            $alt = $this->str($stored[$url] ?? null);
            $alts[$url] = $alt ?? ($i === 0 ? (string) $ctx['name'] : $ctx['name'].' — image '.($i + 1));
        }

        return $alts;
    }

    /**
     * @param  mixed  $ids
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    private function relatedProducts($ids, array $ctx): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
        if ($ids === []) {
            return [];
        }
        $selfId = $ctx['type'] === SeoMeta::TYPE_PRODUCT ? (int) $ctx['key'] : 0;

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->published()
            ->whereIn('id', $ids)
            ->with('defaultVariant')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $p = $products->get($id);
            if (! $p || $id === $selfId) {
                continue;
            }
            $v = $p->defaultVariant;
            $out[] = [
                'id' => $p->id,
                'name' => (string) $p->name,
                'href' => '/products/'.$p->id,
                'thumbnail_img' => $this->absoluteHttpUrl($p->thumbnail_img),
                'unit_price' => $v ? (float) $v->unit_price : null,
                'mrp' => $v && $v->mrp !== null ? (float) $v->mrp : null,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $faq
     * @return list<array{q: string, a: string}>
     */
    private function faq($faq): array
    {
        $out = [];
        foreach (is_array($faq) ? $faq : [] as $item) {
            $q = $this->str(is_array($item) ? ($item['q'] ?? null) : null);
            $a = $this->str(is_array($item) ? ($item['a'] ?? null) : null);
            if ($q !== null && $a !== null) {
                $out[] = ['q' => $q, 'a' => $a];
            }
        }

        return $out;
    }

    public function absoluteHttpUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F<>"]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://[^/\s]+#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    public function plainText(string $html, int $limit): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = Str::squish($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $limit * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:-").'…';
    }

    /**
     * B2B names are often ALL CAPS ("POWER TOOLS"); present them in title case.
     */
    public function label(?string $name): string
    {
        $name = trim((string) $name);
        if ($name !== '' && $name === mb_strtoupper($name) && preg_match('/\p{Lu}{2,}/u', $name) === 1) {
            return Str::title(mb_strtolower($name));
        }

        return $name;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function latest(mixed ...$dates): ?Carbon
    {
        $latest = null;
        foreach ($dates as $date) {
            if ($date instanceof \DateTimeInterface) {
                $c = Carbon::instance($date);
                if ($latest === null || $c->greaterThan($latest)) {
                    $latest = $c;
                }
            }
        }

        return $latest;
    }
}
