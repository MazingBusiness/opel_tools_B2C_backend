<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Http\Resources\ProductListResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\HomeLayout;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Catalog\Support\CatalogSort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Homepage = admin layout (or the default: one band per visible group),
 * resolved against live catalog data. Bands only ever show sellable products:
 * published, not admin-hidden, in stock, effective prices.
 *
 * Cached per CatalogCache version, so a sync, an override save or a layout
 * save all refresh it.
 */
class HomePage
{
    public const TYPES = ['hero', 'category_band', 'banner_row'];

    public const DEFAULT_PRODUCT_LIMIT = 12;

    private const TTL = 600;

    private const BRAND_LIMIT = 8;

    private const FEATURED_CATEGORY_LIMIT = 4;

    public function __construct(private readonly CatalogSearch $search) {}

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    public function resolve(): array
    {
        return Cache::remember(CatalogCache::key('home'), self::TTL, fn (): array => [
            'sections' => $this->build($this->configuredSections()),
        ]);
    }

    /**
     * Saved layout sections, or the default layout when nothing is saved.
     *
     * @return list<array<string, mixed>>
     */
    public function configuredSections(): array
    {
        $layout = HomeLayout::query()->find(HomeLayout::ROW_ID);

        return $layout !== null ? array_values($layout->sections) : $this->defaultSections();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultSections(): array
    {
        $groups = CatalogSort::byAdminOrder(CategoryGroup::query()->visible()->orderBy('name')->get());

        return $groups->map(fn (CategoryGroup $group): array => [
            'id' => 'group-'.$group->id,
            'type' => 'category_band',
            'hidden' => false,
            'group_id' => (int) $group->id,
            'title' => null,
            'product_limit' => self::DEFAULT_PRODUCT_LIMIT,
            'pinned_part_nos' => [],
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function build(array $sections): array
    {
        $out = [];

        foreach ($sections as $section) {
            if (! empty($section['hidden'])) {
                continue;
            }

            $resolved = match ($section['type'] ?? null) {
                'hero' => $this->hero($section),
                'category_band' => $this->band($section),
                'banner_row' => $this->bannerRow($section),
                default => null,
            };

            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function hero(array $section): ?array
    {
        $slides = array_values(array_map(fn (array $s): array => [
            'image_url' => (string) $s['image_url'],
            'link_url' => $s['link_url'] ?? null,
        ], $section['slides'] ?? []));

        return $slides === [] ? null : [
            'id' => (string) $section['id'],
            'type' => 'hero',
            'slides' => $slides,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function bannerRow(array $section): ?array
    {
        $keys = ['eyebrow', 'title', 'subtitle', 'badge', 'cta_label', 'href', 'image_url', 'tone'];
        $banners = array_values(array_map(
            fn (array $b): array => array_merge(array_fill_keys($keys, null), array_intersect_key($b, array_flip($keys))),
            $section['banners'] ?? [],
        ));

        return $banners === [] ? null : [
            'id' => (string) $section['id'],
            'type' => 'banner_row',
            'banners' => $banners,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function band(array $section): ?array
    {
        $group = CategoryGroup::query()->visible()->find((int) ($section['group_id'] ?? 0));
        if ($group === null) {
            return null;
        }

        $limit = max(1, min(24, (int) ($section['product_limit'] ?? self::DEFAULT_PRODUCT_LIMIT)));
        $pinned = $this->pinnedProducts((array) ($section['pinned_part_nos'] ?? []));

        $result = $this->search->paginate([
            'cat_groups' => [$group->id],
            'in_stock' => true,
            'per_page' => min(50, $limit + $pinned->count()),
        ]);

        $products = $pinned
            ->concat($result['products']->getCollection())
            ->unique('id')
            ->take($limit)
            ->values();

        if ($products->isEmpty()) {
            return null;
        }

        $groupHref = '/products?group='.rawurlencode((string) $group->slug);

        return [
            'id' => (string) $section['id'],
            'type' => 'category_band',
            'title' => filled($section['title'] ?? null) ? (string) $section['title'] : (string) $group->name,
            'group' => ['id' => (int) $group->id, 'name' => (string) $group->name, 'slug' => (string) $group->slug],
            'view_all_href' => $groupHref,
            'brands' => $this->brands($result['facets']['brands'] ?? [], $groupHref),
            'featured_categories' => $this->featuredCategories($group, $result['facets']['categories'] ?? [], $groupHref),
            'products' => ProductListResource::collection($products)->resolve(),
        ];
    }

    /**
     * Pinned part numbers in pin order; unknown, unsellable or out-of-stock pins are dropped.
     *
     * @param  array<int, mixed>  $partNos
     * @return Collection<int, Product>
     */
    private function pinnedProducts(array $partNos): Collection
    {
        $partNos = array_values(array_unique(array_filter(array_map(
            fn ($p): string => trim((string) $p),
            $partNos,
        ))));
        if ($partNos === []) {
            return collect();
        }

        $productIdByPart = ProductVariant::query()
            ->whereIn('part_no', $partNos)
            ->pluck('product_id', 'part_no');

        $order = collect($partNos)
            ->map(fn (string $p) => $productIdByPart[$p] ?? null)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($order->isEmpty()) {
            return collect();
        }

        $products = $this->search
            ->withListData($this->search->baseQuery(['in_stock' => true]))
            ->whereIn('products.id', $order->all())
            ->get()
            ->keyBy('id');

        return $order->map(fn (int $id) => $products->get($id))->filter()->values();
    }

    /**
     * @param  list<array{id: int, count: int}>  $facet
     * @return list<array<string, mixed>>
     */
    private function brands(array $facet, string $groupHref): array
    {
        $counts = collect($facet)->sortByDesc('count')->pluck('count', 'id');
        if ($counts->isEmpty()) {
            return [];
        }

        $brands = Brand::query()
            ->where('is_active', true)
            ->where('slug', '!=', 'unbranded')
            ->whereIn('id', $counts->keys()->all())
            ->get()
            ->keyBy('id');

        return $counts->keys()
            ->map(fn ($id) => $brands->get((int) $id))
            ->filter()
            ->take(self::BRAND_LIMIT)
            ->map(fn (Brand $b): array => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'slug' => (string) $b->slug,
                'logo' => $b->logo ?: null,
                'href' => $groupHref.'&brand='.rawurlencode((string) $b->slug),
            ])
            ->values()
            ->all();
    }

    /**
     * Busiest visible categories in the group. Image: admin banner, else a product thumbnail.
     *
     * @param  list<array{id: int, count: int}>  $facet
     * @return list<array<string, mixed>>
     */
    private function featuredCategories(CategoryGroup $group, array $facet, string $groupHref): array
    {
        $counts = collect($facet)->sortByDesc('count')->pluck('count', 'id');
        if ($counts->isEmpty()) {
            return [];
        }

        $categories = Category::query()
            ->visible()
            ->where('category_group_id', $group->id)
            ->whereIn('id', $counts->keys()->all())
            ->get()
            ->keyBy('id');

        return $counts->keys()
            ->map(fn ($id) => $categories->get((int) $id))
            ->filter()
            ->take(self::FEATURED_CATEGORY_LIMIT)
            ->map(fn (Category $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'slug' => (string) $c->slug,
                'image_url' => $this->categoryImage($c),
                'href' => $groupHref.'&category='.rawurlencode((string) $c->slug),
            ])
            ->values()
            ->all();
    }

    private function categoryImage(Category $category): ?string
    {
        $banner = $category->banner;
        if (is_string($banner) && str_starts_with($banner, 'https://')) {
            return $banner;
        }

        $thumb = $this->search->baseQuery(['in_stock' => true])
            ->where('category_id', $category->id)
            ->whereNotNull('thumbnail_img')
            ->orderByDesc('products.id')
            ->value('thumbnail_img');

        return is_string($thumb) && $thumb !== '' ? $thumb : null;
    }
}
