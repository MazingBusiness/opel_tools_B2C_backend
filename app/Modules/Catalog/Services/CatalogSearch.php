<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\PartNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CatalogSearch
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{products: LengthAwarePaginator<int, Product>, facets: array<string, mixed>, matched_variant_ids: array<int, int>}
     */
    public function paginate(array $filters): array
    {
        $query = $this->baseQuery($filters);
        $facets = $this->facets((clone $query)->reorder());

        $q = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? null;

        $query->addSelect([
            'default_unit_price' => ProductVariant::query()
                ->select('unit_price')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('is_default', true)
                ->limit(1),
        ]);

        if ($q !== '') {
            $this->orderByRelevance($query, $q);
            if (is_string($sort) && $sort !== '') {
                $this->applySort($query, $sort);
            }
        } else {
            $this->applySort($query, is_string($sort) && $sort !== '' ? $sort : 'new_arrival');
        }

        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));

        $products = $query
            ->with([
                'brand',
                'category',
                'categoryGroup',
                'defaultVariant',
            ])
            ->withCount('variants')
            ->withExists(['variants as in_stock' => function (Builder $variant): void {
                $variant->where('current_stock', '>', 0);
            }])
            ->paginate($perPage)
            ->withQueryString();

        $matched = $q !== ''
            ? $this->matchedVariantIds($products->getCollection(), $q)
            : [];

        $products->getCollection()->transform(function (Product $product) use ($matched) {
            $product->setAttribute('matched_variant_id', $matched[$product->id] ?? null);

            return $product;
        });

        return [
            'products' => $products,
            'facets' => $facets,
            'matched_variant_ids' => $matched,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function baseQuery(array $filters): Builder
    {
        $query = Product::query()->published();

        $groupIds = $this->ids($filters, 'cat_groups', 'group_id');
        if ($groupIds !== []) {
            $query->whereIn('group_id', $groupIds);
        }

        $categoryIds = $this->ids($filters, 'categories', 'category_id');
        if ($categoryIds !== []) {
            $query->whereIn('category_id', $categoryIds);
        }

        $brandIds = $this->ids($filters, 'brands', 'brand_id');
        if ($brandIds !== []) {
            $query->whereIn('brand_id', $brandIds);
        }

        if (isset($filters['min']) && $filters['min'] !== null && $filters['min'] !== '') {
            $min = $filters['min'];
            $query->whereHas('defaultVariant', function (Builder $variant) use ($min): void {
                $variant->where('unit_price', '>=', $min);
            });
        }

        if (isset($filters['max']) && $filters['max'] !== null && $filters['max'] !== '') {
            $max = $filters['max'];
            $query->whereHas('defaultVariant', function (Builder $variant) use ($max): void {
                $variant->where('unit_price', '<=', $max);
            });
        }

        if (($filters['in_stock'] ?? null) === true || ($filters['in_stock'] ?? null) === 1 || ($filters['in_stock'] ?? null) === '1') {
            $query->whereHas('variants', function (Builder $variant): void {
                $variant->where('current_stock', '>', 0);
            });
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $this->constrainSearch($query, $q);
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function constrainSearch(Builder $query, string $q): void
    {
        $normalized = PartNumber::normalize($q);
        $like = $this->escapeLike($q);
        $prefix = $this->escapeLike($normalized).'%';
        $driver = DB::connection()->getDriverName();

        $query->where(function (Builder $outer) use ($q, $normalized, $like, $prefix, $driver): void {
            $outer->whereHas('variants', function (Builder $variant) use ($q, $normalized, $prefix): void {
                $variant->where('part_no', $q)
                    ->orWhere('part_no_normalized', $normalized)
                    ->orWhere('part_no_normalized', 'like', $prefix);
            });

            if ($driver === 'mysql') {
                $outer->orWhereFullText('name', $q);
            } else {
                $outer->orWhere('name', 'like', '%'.$like.'%');
            }
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function orderByRelevance(Builder $query, string $q): void
    {
        $normalized = PartNumber::normalize($q);
        $prefix = $normalized.'%';

        $query->orderByRaw(
            'case
                when exists (
                    select 1 from product_variants
                    where product_variants.product_id = products.id
                      and (product_variants.part_no = ? or product_variants.part_no_normalized = ?)
                ) then 0
                when exists (
                    select 1 from product_variants
                    where product_variants.product_id = products.id
                      and product_variants.part_no_normalized like ?
                ) then 1
                else 2
            end',
            [$q, $normalized, $prefix],
        );
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_low_to_high' => $query->orderBy('default_unit_price')->orderByDesc('products.id'),
            'price_high_to_low' => $query->orderByDesc('default_unit_price')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.id'),
        };
    }

    /**
     * @param  Builder<Product>  $query
     * @return array<string, mixed>
     */
    private function facets(Builder $query): array
    {
        $ids = (clone $query)->select('products.id');

        $groupCounts = (clone $query)
            ->select('group_id', DB::raw('count(*) as aggregate'))
            ->groupBy('group_id')
            ->pluck('aggregate', 'group_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $categoryCounts = (clone $query)
            ->select('category_id', DB::raw('count(*) as aggregate'))
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $brandCounts = (clone $query)
            ->select('brand_id', DB::raw('count(*) as aggregate'))
            ->groupBy('brand_id')
            ->pluck('aggregate', 'brand_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $prices = ProductVariant::query()
            ->where('is_default', true)
            ->whereIn('product_id', $ids)
            ->selectRaw('min(unit_price) as min_price, max(unit_price) as max_price')
            ->first();

        return [
            'groups' => $this->facetList($groupCounts),
            'categories' => $this->facetList($categoryCounts),
            'brands' => $this->facetList($brandCounts),
            'min_price' => $prices?->min_price !== null ? (string) $prices->min_price : null,
            'max_price' => $prices?->max_price !== null ? (string) $prices->max_price : null,
        ];
    }

    /**
     * @param  array<int|string, int>  $counts
     * @return list<array{id: int, count: int}>
     */
    private function facetList(array $counts): array
    {
        $list = [];

        foreach ($counts as $id => $count) {
            $list[] = [
                'id' => (int) $id,
                'count' => $count,
            ];
        }

        return $list;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    private function matchedVariantIds(Collection $products, string $q): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $normalized = PartNumber::normalize($q);
        $prefix = $this->escapeLike($normalized).'%';

        return ProductVariant::query()
            ->whereIn('product_id', $products->modelKeys())
            ->where(function (Builder $variant) use ($q, $normalized, $prefix): void {
                $variant->where('part_no', $q)
                    ->orWhere('part_no_normalized', $normalized)
                    ->orWhere('part_no_normalized', 'like', $prefix);
            })
            ->orderByRaw(
                'case
                    when part_no = ? or part_no_normalized = ? then 0
                    else 1
                end',
                [$q, $normalized],
            )
            ->get()
            ->unique('product_id')
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->product_id => $variant->id])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function ids(array $filters, string $listKey, string $singleKey): array
    {
        $ids = [];

        if (isset($filters[$singleKey]) && $filters[$singleKey] !== null && $filters[$singleKey] !== '') {
            $ids[] = (int) $filters[$singleKey];
        }

        foreach ((array) ($filters[$listKey] ?? []) as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
