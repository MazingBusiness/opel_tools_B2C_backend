<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogSearch;
use Illuminate\Support\Collection;

/**
 * Resolves category card images in batch: admin https:// banner first,
 * falling back to the newest sellable product thumbnail in a single pair of queries.
 */
final class CategoryImages
{
    public static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $value)) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($value, PHP_URL_HOST) !== ''
            ? $value
            : null;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, string|null>
     */
    public static function forCategories(Collection $categories, CatalogSearch $search): array
    {
        $out = [];
        $missingIds = [];

        foreach ($categories as $category) {
            $banner = self::httpsUrl($category->banner);
            if ($banner !== null) {
                $out[(int) $category->id] = $banner;
            } else {
                $out[(int) $category->id] = null;
                $missingIds[] = (int) $category->id;
            }
        }

        if ($missingIds === []) {
            return $out;
        }

        $latestIds = $search->baseQuery(['in_stock' => true])
            ->whereIn('products.category_id', $missingIds)
            ->whereNotNull('products.thumbnail_img')
            ->where('products.thumbnail_img', '!=', '')
            ->selectRaw('max(products.id) as max_id')
            ->groupBy('products.category_id')
            ->pluck('max_id');

        if ($latestIds->isEmpty()) {
            return $out;
        }

        $thumbs = Product::query()
            ->whereIn('id', $latestIds->all())
            ->pluck('thumbnail_img', 'category_id');

        foreach ($thumbs as $categoryId => $thumb) {
            $url = self::httpsUrl($thumb);
            if ($url !== null) {
                $out[(int) $categoryId] = $url;
            }
        }

        return $out;
    }
}
