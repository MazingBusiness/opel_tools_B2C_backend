<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminSeoTargetsRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminSeoRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Seo\Models\SeoMeta;
use App\Modules\Seo\Services\SeoResolver;
use App\Modules\Seo\Support\HtmlSanitizer;
use App\Modules\Seo\Support\SeoCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin SEO v0 — list targets by type, read and save per-target overrides.
 * Staff only (auth:sanctum + admin).
 */
class AdminSeoController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function __construct(private SeoResolver $seo)
    {
    }

    public function targets(AdminSeoTargetsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $type = $filters['type'] ?? 'pages';
        $q = (string) ($filters['q'] ?? '');
        $page = (int) ($filters['page'] ?? 1);
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));

        $paginator = match ($type) {
            'products' => $this->productTargets($q, $perPage),
            'categories' => $this->paginateCollection($this->categoryTargets($q), $page, $perPage),
            default => $this->paginateCollection($this->pageTargets($q), $page, $perPage),
        };

        $rows = collect($paginator->items());
        $metas = $this->metasFor($rows);

        return response()->json([
            'ok' => true,
            'data' => $rows->map(function (array $row) use ($metas): array {
                /** @var SeoMeta|null $meta */
                $meta = $metas->get($row['type'].':'.$row['key']);

                return array_merge($row, [
                    'url' => $this->seo->storefrontUrl($row['path']),
                    'has_meta' => $meta !== null,
                    'seo_title' => $meta?->seo_title,
                    'robots' => $meta?->robots ?? 'index',
                    'meta_updated_at' => $meta?->updated_at?->toIso8601String(),
                ]);
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(string $type, string $key): JsonResponse
    {
        return $this->payload($type, $key);
    }

    public function update(UpdateAdminSeoRequest $request, string $type, string $key): JsonResponse
    {
        $ctx = $this->target($type, $key);
        $data = $request->validated();
        $errors = [];

        // Related products: must exist and not point at itself.
        $related = array_values(array_unique(array_map('intval', $data['related_product_ids'] ?? [])));
        if ($related !== []) {
            if ($type === SeoMeta::TYPE_PRODUCT && in_array((int) $key, $related, true)) {
                $errors['related_product_ids'] = 'A product cannot be related to itself.';
            }
            $existing = Product::query()->whereIn('id', $related)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($related, $existing);
            if ($missing !== []) {
                $errors['related_product_ids'] = 'Unknown product id(s): '.implode(', ', $missing).'.';
            }
        }

        // Image alts: products only; keys must be this product's images.
        $alts = [];
        foreach (($data['image_alts'] ?? []) as $item) {
            $url = (string) $item['url'];
            $alt = is_string($item['alt'] ?? null) ? trim($item['alt']) : '';
            if ($alt === '') {
                continue;
            }
            if ($type !== SeoMeta::TYPE_PRODUCT || ! in_array((string) $url, $ctx['images'], true)) {
                $errors['image_alts'] = 'Image alts can only be set for this product\'s own images.';
                break;
            }
            $alts[$url] = $alt;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $faq = collect($data['faq'] ?? [])
            ->map(fn (array $item): array => ['q' => trim((string) $item['q']), 'a' => trim((string) $item['a'])])
            ->filter(fn (array $item): bool => $item['q'] !== '' && $item['a'] !== '')
            ->values()
            ->all();

        $keywords = collect($data['secondary_keywords'] ?? [])
            ->map(fn ($k): string => trim((string) $k))
            ->filter()
            ->unique(fn (string $k): string => mb_strtolower($k))
            ->values()
            ->all();

        $schema = $data['schema_jsonld'] ?? null;
        if ($schema !== null) {
            // Store normalized JSON, never the raw string.
            $schema = json_encode(json_decode($schema, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $attributes = [
                'seo_title' => $data['seo_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'h1' => $data['h1'] ?? null,
                'content' => HtmlSanitizer::clean($data['content'] ?? null),
                'primary_keyword' => $data['primary_keyword'] ?? null,
                'secondary_keywords' => $keywords === [] ? null : $keywords,
                'canonical_url' => $data['canonical_url'] ?? null,
                'robots' => $data['robots'] ?? null,
                'og_title' => $data['og_title'] ?? null,
                'og_description' => $data['og_description'] ?? null,
                'og_image' => $data['og_image'] ?? null,
                'schema_jsonld' => $schema,
                'faq' => $faq === [] ? null : $faq,
                'related_product_ids' => $related === [] ? null : $related,
                'image_alts' => $alts === [] ? null : $alts,
        ];
        // Only keys present in the request are written: a MISSING key keeps the
        // stored value; an explicit null / "" / [] clears it.
        $attributes = array_intersect_key($attributes, $data);

        $conflict = ! $this->saveVersioned($type, (string) $ctx['key'], (int) $data['expected_version'], $attributes);

        if ($conflict) {
            return response()->json([
                'message' => 'This SEO entry was changed elsewhere (another tab or user) after you opened it. Reload to get the latest version, then re-apply your edits.',
                'current' => $this->payload($type, $key)->getData(true),
            ], Response::HTTP_CONFLICT);
        }

        // Public cache invalidation lives here (no model events: saveVersioned() uses a
        // raw conditional UPDATE). Runs for both create and update; never on 409.
        SeoCache::bump();

        return $this->payload($type, $key);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Atomic optimistic-concurrency write. Returns false on conflict (nothing written).
     *
     * expected 0 → row must not exist: plain INSERT (version 1); if a row already
     *   exists (or another request created it concurrently) the unique
     *   (target_type, target_key) index rejects it → conflict.
     * expected N → single statement:
     *   UPDATE seo_meta SET …, version = version + 1, updated_at = ?
     *   WHERE target_type = ? AND target_key = ? AND version = N
     *   0 rows affected → conflict (stale version or row missing).
     *
     * Only keys in $attributes are written (omitted keys keep stored values).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveVersioned(string $type, string $key, int $expected, array $attributes): bool
    {
        if ($expected === 0) {
            try {
                SeoMeta::query()->create(['target_type' => $type, 'target_key' => $key, 'version' => 1] + $attributes);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        // Run values through the model's casts (array → JSON) for the raw UPDATE.
        $columns = (new SeoMeta)->fill($attributes)->getAttributes();

        $affected = SeoMeta::query()
            ->where('target_type', $type)
            ->where('target_key', $key)
            ->where('version', $expected)
            ->update($columns + ['version' => DB::raw('version + 1')]); // Eloquent adds updated_at

        return $affected === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function target(string $type, string $key): array
    {
        $ctx = in_array($type, SeoMeta::TYPES, true) ? $this->seo->forTarget($type, $key) : null;
        if ($ctx === null) {
            abort(Response::HTTP_NOT_FOUND, 'SEO target not found.');
        }

        return $ctx;
    }

    private function payload(string $type, string $key): JsonResponse
    {
        $ctx = $this->target($type, $key);
        /** @var SeoMeta|null $meta */
        $meta = $ctx['meta'];

        // Preview as if public so admins see overrides on hidden targets too.
        $preview = $this->seo->present(array_merge($ctx, ['public' => true]));

        $relatedIds = array_values(array_map('intval', $meta?->related_product_ids ?? []));
        $related = $relatedIds === []
            ? collect()
            : Product::query()->whereIn('id', $relatedIds)->get(['id', 'name', 'thumbnail_img', 'published', 'approved'])->keyBy('id');

        return response()->json([
            'ok' => true,
            'target' => [
                'type' => $ctx['type'],
                'key' => (string) $ctx['key'],
                'name' => $ctx['name'],
                'path' => $ctx['path'],
                'url' => $this->seo->storefrontUrl($ctx['path']),
                'public' => (bool) $ctx['public'],
                'images' => $ctx['images'],
                'defaults' => $ctx['defaults'],
                'breadcrumbs' => $ctx['breadcrumbs'],
            ],
            'meta' => [
                'seo_title' => $meta?->seo_title,
                'meta_description' => $meta?->meta_description,
                'h1' => $meta?->h1,
                'content' => $meta?->content,
                'primary_keyword' => $meta?->primary_keyword,
                'secondary_keywords' => $meta?->secondary_keywords ?? [],
                'canonical_url' => $meta?->canonical_url,
                'robots' => $meta?->robots ?? 'index',
                'og_title' => $meta?->og_title,
                'og_description' => $meta?->og_description,
                'og_image' => $meta?->og_image,
                'schema_jsonld' => $meta?->schema_jsonld,
                'faq' => $meta?->faq ?? [],
                'related_product_ids' => $relatedIds,
                'related_products' => collect($relatedIds)
                    ->map(fn (int $id) => $related->get($id))
                    ->filter()
                    ->map(fn (Product $p): array => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'thumbnail_img' => $p->thumbnail_img,
                        'published' => (bool) $p->published && (bool) $p->approved,
                    ])
                    ->values(),
                'image_alts' => (object) ($meta?->image_alts ?? []),
                'version' => (int) ($meta?->version ?? 0), // 0 = no row yet; send back as expected_version
                'updated_at' => $meta?->updated_at?->toIso8601String(),
            ],
            'preview' => [
                'title' => $preview['title'],
                'description' => $preview['description'],
                'canonical_url' => $preview['canonical_url'] ?? $this->seo->storefrontUrl($ctx['path']),
                'robots' => $ctx['public'] ? $preview['robots'] : 'noindex,follow',
                'h1' => $preview['h1'],
            ],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pageTargets(string $q): Collection
    {
        return collect((array) config('seo.pages', []))
            ->map(fn (array $page, string $key): array => [
                'type' => SeoMeta::TYPE_PAGE,
                'key' => $key,
                'name' => (string) $page['name'],
                'path' => (string) $page['path'],
                'public' => true,
                'kind' => 'Page',
            ])
            ->filter(fn (array $row): bool => $q === '' || $this->matches($q, $row['name'], $row['path'], $row['key']))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function categoryTargets(string $q): Collection
    {
        $groups = CategoryGroup::query()->orderBy('name')->get();
        $groupsById = $groups->keyBy('id');

        $rows = collect();
        foreach ($groups as $group) {
            $rows->push([
                'type' => SeoMeta::TYPE_CATEGORY_GROUP,
                'key' => (string) $group->id,
                'name' => $this->seo->label($group->name),
                'path' => '/category/'.$group->slug,
                'public' => (bool) $group->is_active,
                'kind' => 'Category group',
            ]);
        }

        foreach (Category::query()->orderBy('name')->get() as $category) {
            $group = $groupsById->get($category->category_group_id);
            $rows->push([
                'type' => SeoMeta::TYPE_CATEGORY,
                'key' => (string) $category->id,
                'name' => $this->seo->label($category->name),
                'path' => '/category/'.($group?->slug ?? 'all').'/'.$category->slug,
                'public' => (bool) $category->is_active && (bool) ($group?->is_active ?? false),
                'kind' => 'Category',
            ]);
        }

        return $rows
            ->filter(fn (array $row): bool => $q === '' || $this->matches($q, $row['name'], $row['path']))
            ->values();
    }

    private function productTargets(string $q, int $perPage): LengthAwarePaginator
    {
        $query = Product::query()->select(['id', 'name', 'slug', 'thumbnail_img', 'published', 'approved']);

        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($q, $like): void {
                $w->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('part_no', 'like', $like));
                if (ctype_digit($q) && strlen($q) <= 18) {
                    $w->orWhere('id', (int) $q);
                }
            });
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate($perPage)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (Product $p): array => [
            'type' => SeoMeta::TYPE_PRODUCT,
            'key' => (string) $p->id,
            'name' => (string) $p->name,
            'path' => '/products/'.$p->id,
            'public' => (bool) $p->published && (bool) $p->approved,
            'kind' => 'Product',
            'thumbnail_img' => $p->thumbnail_img,
        ]));

        return $page;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<string, SeoMeta>
     */
    private function metasFor(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $query = SeoMeta::query();
        $query->where(function (Builder $w) use ($rows): void {
            foreach ($rows->groupBy('type') as $type => $group) {
                $w->orWhere(fn (Builder $t) => $t->where('target_type', $type)->whereIn('target_key', $group->pluck('key')->all()));
            }
        });

        return $query->get()->keyBy(fn (SeoMeta $m): string => $m->target_type.':'.$m->target_key);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginateCollection(Collection $rows, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
        );
    }

    private function matches(string $q, string ...$haystacks): bool
    {
        foreach ($haystacks as $h) {
            if (mb_stripos($h, $q) !== false) {
                return true;
            }
        }

        return false;
    }
}
