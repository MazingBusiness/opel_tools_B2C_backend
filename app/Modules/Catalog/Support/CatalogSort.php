<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Storefront order for groups / categories: admin sort_order first (ascending,
 * unset last), then effective name, then id.
 */
final class CatalogSort
{
    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $items
     * @return Collection<int, T>
     */
    public static function byAdminOrder(Collection $items): Collection
    {
        return $items->sort(function (Model $a, Model $b): int {
            $sa = $a->adminSortOrder();
            $sb = $b->adminSortOrder();
            if ($sa !== $sb) {
                if ($sa === null) {
                    return 1;
                }
                if ($sb === null) {
                    return -1;
                }

                return $sa <=> $sb;
            }

            return [mb_strtolower((string) $a->name), $a->getKey()] <=> [mb_strtolower((string) $b->name), $b->getKey()];
        })->values();
    }
}
