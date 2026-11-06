<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin "hidden" overrides, with cascade:
 *  - a hidden group hides its categories and their products;
 *  - a hidden category hides its subcategories (any depth) and their products;
 *  - a hidden product is hidden on its own.
 * Hidden ids are cached per CatalogCache version (bumped on every save).
 */
final class CatalogVisibility
{
    private const TTL = 3600;

    /**
     * @return list<int>
     */
    public static function hiddenGroupIds(): array
    {
        return Cache::remember(CatalogCache::key('hidden-groups'), self::TTL, fn (): array => DB::table('category_group_overrides')
            ->where('hidden', true)
            ->orderBy('category_group_id')
            ->pluck('category_group_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Own hidden + in hidden group + descendants of hidden categories.
     *
     * @return list<int>
     */
    public static function hiddenCategoryIds(): array
    {
        return Cache::remember(CatalogCache::key('hidden-categories'), self::TTL, function (): array {
            $hidden = [];
            foreach (DB::table('category_overrides')->where('hidden', true)->pluck('category_id') as $id) {
                $hidden[(int) $id] = true;
            }

            $groups = array_flip(self::hiddenGroupIds());
            if ($hidden === [] && $groups === []) {
                return [];
            }

            $rows = DB::table('categories')->get(['id', 'parent_id', 'category_group_id']);
            foreach ($rows as $row) {
                if (isset($groups[(int) $row->category_group_id])) {
                    $hidden[(int) $row->id] = true;
                }
            }

            do {
                $changed = false;
                foreach ($rows as $row) {
                    if ($row->parent_id !== null && isset($hidden[(int) $row->parent_id]) && ! isset($hidden[(int) $row->id])) {
                        $hidden[(int) $row->id] = true;
                        $changed = true;
                    }
                }
            } while ($changed);

            $ids = array_keys($hidden);
            sort($ids);

            return $ids;
        });
    }

    public static function groupHidden(?int $groupId): bool
    {
        return $groupId !== null && in_array($groupId, self::hiddenGroupIds(), true);
    }

    public static function categoryHidden(?int $categoryId): bool
    {
        return $categoryId !== null && in_array($categoryId, self::hiddenCategoryIds(), true);
    }
}
