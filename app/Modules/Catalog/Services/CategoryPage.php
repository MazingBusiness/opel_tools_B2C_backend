<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Http\Resources\ProductListResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Catalog\Support\CatalogSort;
use App\Modules\Catalog\Support\CategoryImages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Group and category browse pages built from live catalog data.
 * Uses only published, unhidden, in-stock products with effective prices,
 * cached per CatalogCache version.
 */
class CategoryPage
{
    private const TTL = 600;

    private const SPOTLIGHT_LIMIT = 6;

    private const SPOTLIGHT_BRAND_LIMIT = 6;

    private const SPOTLIGHT_PRODUCT_LIMIT = 10;

    private const GRID_PRODUCT_LIMIT = 20;

    public function __construct(private readonly CatalogSearch $search) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $slug, ?string $subSlug = null): ?array
    {
        $key = CatalogCache::key('category_page:'.sha1($slug.'/'.($subSlug ?? '')));
        $cached = Cache::remember($key, self::TTL, fn () => $this->build($slug, $subSlug) ?? false);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(string $slug, ?string $subSlug): ?array
    {
        if ($subSlug === null) {
            $group = CategoryGroup::query()->visible()->where('slug', $slug)->first();
            if ($group !== null) {
                return $this->groupPage($group);
            }

            $category = Category::query()
                ->visible()
                ->where('slug', $slug)
                ->whereHas('categoryGroup', fn ($q) => $q->visible())
                ->with('categoryGroup')
                ->first();

            if ($category === null || $category->categoryGroup === null) {
                return null;
            }

            return [
                'redirect_to' => $this->categoryHref($category->categoryGroup, $category),
            ];
        }

        $group = CategoryGroup::query()->visible()->where('slug', $slug)->first();
        if ($group === null) {
            return null;
        }

        $category = Category::query()
            ->visible()
            ->where('slug', $subSlug)
            ->where('category_group_id', $group->id)
            ->with('parent')
            ->first();

        if ($category === null) {
            return null;
        }

        return $this->categoryPage($group, $category);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupPage(CategoryGroup $group): array
    {
        $allCategories = CatalogSort::byAdminOrder(
            Category::query()->visible()->where('category_group_id', $group->id)->get()
        );
        $topLevel = $allCategories->whereNull('parent_id')->values();
        $stripCategories = $topLevel->isNotEmpty() ? $topLevel : $allCategories;

        $result = $this->search->paginate([
            'cat_groups' => [$group->id],
            'in_stock' => true,
            'per_page' => self::GRID_PRODUCT_LIMIT,
        ]);

        $counts = collect($result['facets']['categories'] ?? [])->sortByDesc('count')->pluck('count', 'id');
        $byId = $allCategories->keyBy('id');
        $spotlightCats = $counts->keys()
            ->map(fn ($id) => $byId->get((int) $id))
            ->filter()
            ->take(self::SPOTLIGHT_LIMIT)
            ->values();

        $images = CategoryImages::forCategories($stripCategories, $this->search);

        return [
            'kind' => 'group',
            'slug' => (string) $group->slug,
            'title' => (string) $group->name,
            'description' => null,
            'breadcrumbs' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => (string) $group->name],
            ],
            'hero_slides' => [],
            'children_label' => 'Shop by sub-category',
            'children' => $stripCategories->map(fn (Category $c): array => [
                'id' => (int) $c->id,
                'slug' => (string) $c->slug,
                'title' => (string) $c->name,
                'image_url' => $images[$c->id] ?? null,
                'href' => $this->categoryHref($group, $c),
            ])->values()->all(),
            'child_spotlights' => $this->spotlights($group, $spotlightCats),
            'products' => ProductListResource::collection($result['products']->getCollection())->resolve(),
            'products_href' => '/products?group='.rawurlencode((string) $group->slug),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPage(CategoryGroup $group, Category $category): array
    {
        $children = CatalogSort::byAdminOrder(
            Category::query()
                ->visible()
                ->where('category_group_id', $group->id)
                ->where('parent_id', $category->id)
                ->get()
        );

        $siblings = $children->isEmpty()
            ? CatalogSort::byAdminOrder(
                Category::query()
                    ->visible()
                    ->where('category_group_id', $group->id)
                    ->where('id', '!=', $category->id)
                    ->when(
                        $category->parent_id !== null,
                        fn ($q) => $q->where('parent_id', $category->parent_id),
                        fn ($q) => $q->whereNull('parent_id'),
                    )
                    ->get()
            )
            : collect();

        $strip = $children->isNotEmpty() ? $children : $siblings;
        $stripLabel = $children->isNotEmpty() ? 'Shop by sub-category' : 'More in '.$group->name;
        $images = CategoryImages::forCategories($strip, $this->search);

        $filterCategoryIds = [$category->id, ...$children->pluck('id')->all()];
        $result = $this->search->paginate([
            'categories' => $filterCategoryIds,
            'in_stock' => true,
            'per_page' => self::GRID_PRODUCT_LIMIT,
        ]);

        $spotlights = [];
        if ($children->isNotEmpty()) {
            $counts = collect($result['facets']['categories'] ?? [])->sortByDesc('count')->pluck('count', 'id');
            $byId = $children->keyBy('id');
            $spotlightCats = $counts->keys()
                ->map(fn ($id) => $byId->get((int) $id))
                ->filter()
                ->take(self::SPOTLIGHT_LIMIT)
                ->values();
            $spotlights = $this->spotlights($group, $spotlightCats);
        }

        $breadcrumbs = [
            ['label' => 'Home', 'href' => '/'],
            ['label' => (string) $group->name, 'href' => $this->groupHref($group)],
        ];
        if ($category->parent !== null && $category->parent->is_active) {
            $breadcrumbs[] = [
                'label' => (string) $category->parent->name,
                'href' => $this->categoryHref($group, $category->parent),
            ];
        }
        $breadcrumbs[] = ['label' => (string) $category->name];

        $banner = CategoryImages::httpsUrl($category->banner);
        $heroSlides = $banner !== null
            ? [['id' => 'category-banner-'.$category->id, 'image_url' => $banner, 'link_url' => null]]
            : [];

        return [
            'kind' => 'category',
            'slug' => (string) $category->slug,
            'title' => (string) $category->name,
            'description' => null,
            'breadcrumbs' => $breadcrumbs,
            'hero_slides' => $heroSlides,
            'children_label' => $stripLabel,
            'children' => $strip->map(fn (Category $c): array => [
                'id' => (int) $c->id,
                'slug' => (string) $c->slug,
                'title' => (string) $c->name,
                'image_url' => $images[$c->id] ?? null,
                'href' => $this->categoryHref($group, $c),
            ])->values()->all(),
            'child_spotlights' => $spotlights,
            'products' => ProductListResource::collection($result['products']->getCollection())->resolve(),
            'products_href' => $this->productsFilterHref($group, $category),
        ];
    }

    /**
     * Build spotlight rows for up to SPOTLIGHT_LIMIT categories in batch (no per-category N+1).
     *
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function spotlights(CategoryGroup $group, Collection $categories): array
    {
        $categoryIds = $categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($categoryIds === []) {
            return [];
        }

        $brandRows = $this->search->baseQuery(['in_stock' => true])
            ->whereIn('products.category_id', $categoryIds)
            ->whereNotNull('products.brand_id')
            ->select('products.category_id', 'products.brand_id', DB::raw('count(*) as aggregate'))
            ->groupBy('products.category_id', 'products.brand_id')
            ->orderByDesc('aggregate')
            ->get();

        $brandIdsByCat = [];
        $allBrandIds = [];
        foreach ($brandRows as $row) {
            $cid = (int) $row->category_id;
            $bid = (int) $row->brand_id;
            $brandIdsByCat[$cid][] = $bid;
            $allBrandIds[$bid] = true;
        }

        $brands = $allBrandIds === []
            ? collect()
            : Brand::query()
                ->where('is_active', true)
                ->where('slug', '!=', 'unbranded')
                ->whereIn('id', array_keys($allBrandIds))
                ->get()
                ->keyBy('id');

        $productRows = $this->search->baseQuery(['in_stock' => true])
            ->whereIn('products.category_id', $categoryIds)
            ->orderByRaw('(select po.sort_order from product_overrides po where po.product_id = products.id) is null')
            ->orderByRaw('(select po.sort_order from product_overrides po where po.product_id = products.id)')
            ->orderByDesc('products.id')
            ->toBase()
            ->get(['products.id', 'products.category_id']);

        $productIdsByCat = [];
        $allProductIds = [];
        foreach ($productRows as $row) {
            $cid = (int) $row->category_id;
            $pid = (int) $row->id;
            if (count($productIdsByCat[$cid] ?? []) < self::SPOTLIGHT_PRODUCT_LIMIT) {
                $productIdsByCat[$cid][] = $pid;
                $allProductIds[] = $pid;
            }
        }

        $productsById = $allProductIds === []
            ? collect()
            : $this->search->withListData(Product::query()->whereIn('products.id', array_unique($allProductIds)))
                ->get()
                ->keyBy('id');

        $out = [];
        foreach ($categories as $cat) {
            $cid = (int) $cat->id;
            $catProducts = collect($productIdsByCat[$cid] ?? [])
                ->map(fn (int $id) => $productsById->get($id))
                ->filter()
                ->values();

            if ($catProducts->isEmpty()) {
                continue;
            }

            $filterBase = $this->productsFilterHref($group, $cat);
            $catBrands = collect($brandIdsByCat[$cid] ?? [])
                ->map(fn (int $bid) => $brands->get($bid))
                ->filter()
                ->take(self::SPOTLIGHT_BRAND_LIMIT)
                ->map(fn (Brand $b): array => [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                    'slug' => (string) $b->slug,
                    'logo' => CategoryImages::httpsUrl($b->logo),
                    'href' => $filterBase.'&brand='.rawurlencode((string) $b->slug),
                ])
                ->values()
                ->all();

            $out[] = [
                'id' => $cid,
                'slug' => (string) $cat->slug,
                'title' => (string) $cat->name,
                'view_all_href' => $this->categoryHref($group, $cat),
                'brands' => $catBrands,
                'products' => ProductListResource::collection($catProducts)->resolve(),
            ];
        }

        return $out;
    }

    private function groupHref(CategoryGroup $group): string
    {
        return '/category/'.rawurlencode((string) $group->slug);
    }

    private function categoryHref(CategoryGroup $group, Category $category): string
    {
        return $this->groupHref($group).'/'.rawurlencode((string) $category->slug);
    }

    private function productsFilterHref(CategoryGroup $group, Category $category): string
    {
        return '/products?group='.rawurlencode((string) $group->slug).'&category='.rawurlencode((string) $category->slug);
    }
}
