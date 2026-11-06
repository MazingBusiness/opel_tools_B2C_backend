<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Support\PartNumber;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared B2B → B2C catalog writer used by catalog:sync-b2b and CatalogSeeder.
 *
 * Match existing rows by part_no. Never change id or slug on an existing row.
 * Never touch override tables during upsert. wipeDemoSeed() deletes only
 * fixture rows whose local id and part_no still match.
 */
class B2bCatalogSync
{
    /**
     * Longest prefix first so "Forever Gold" wins over "Forever".
     *
     * @var list<array{0: string, 1: string}>
     */
    public const BRAND_PREFIXES = [
        ['FOREVER GOLD', 'Forever Gold'],
        ['JON BHANDARI', 'Jon Bhandari'],
        ['GOLDEN BULLET', 'Golden Bullet'],
        ['SILVER BULLET', 'Silver Bullet'],
        ['XTRA POWER', 'Xtra Power'],
        ['AUTO POWER', 'Auto Power'],
        ['AXTRIM PRO', 'Axtrim Pro'],
        ['BLACK STAR', 'Black Star'],
        ['KP TECH', 'KP Tech'],
        ['HI MAX', 'Hi Max'],
        ['POWERMATIC', 'Powermatic'],
        ['MASTECH', 'Mastech'],
        ['BHAVANI', 'Bhavani'],
        ['EVERRON', 'Everron'],
        ['CASTLE', 'Castle'],
        ['MATRIX', 'Matrix'],
        ['MAKUTE', 'Makute'],
        ['AMBER', 'Amber'],
        ['EAGLE', 'Eagle'],
        ['AKARI', 'Akari'],
        ['POKER', 'Poker'],
        ['YOCA', 'Yoca'],
        ['XLNT', 'XLNT'],
        ['DELI', 'Deli'],
        ['TCA', 'TCA'],
        ['MAF', 'MAF'],
        ['OPEL', 'OPEL'],
        ['FOREVER', 'Forever'],
    ];

    /**
     * @var array<string, string>
     */
    public const WAREHOUSE_CODES = [
        'Delhi' => 'DELHI',
        'Mumbai' => 'MUMBAI',
        'Kolkata' => 'KOLKATA',
    ];

    /**
     * Brand ids by slug (brands are matched by slug, not fixed ids).
     *
     * @var array<string, int>
     */
    private array $brands = [];

    public function upsertTax(DateTimeInterface $now): int
    {
        $id = DB::table('taxes')->where('name', 'GST')->orderBy('id')->value('id');
        if ($id !== null) {
            DB::table('taxes')->where('id', $id)->update(['rate' => '18.00', 'updated_at' => $now]);

            return (int) $id;
        }

        return (int) DB::table('taxes')->insertGetId([
            'name' => 'GST', 'rate' => '18.00', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Delete fixture products only when local id and part_no still match the
     * seed (MZ11018 at id 1 goes; MZ11018 at id 9520 is left alone). Cascades
     * variants, warehouses, and carts. Overrides and product SEO are removed
     * only for those ids. Orders, users, CMS, categories, and groups stay.
     */
    public function wipeDemoSeed(): int
    {
        $path = database_path('seeders/data/b2b-air-blowers.json');
        $items = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($items)) {
            throw new RuntimeException('Catalog fixture must be a JSON array.');
        }

        $targets = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null) || ! is_numeric($item['variant_id'] ?? null)) {
                continue;
            }
            $productId = (int) $item['id'];
            $variantId = (int) $item['variant_id'];
            $partNo = (string) ($item['part_no'] ?? '');
            $variant = DB::table('product_variants')->where('id', $variantId)->first(['id', 'product_id', 'part_no']);
            if ($variant === null || $variant->part_no !== $partNo || (int) $variant->product_id !== $productId) {
                continue;
            }
            $targets[] = ['product_id' => $productId, 'variant_id' => $variantId];
        }

        if ($targets === []) {
            return 0;
        }

        $productIds = array_values(array_unique(array_column($targets, 'product_id')));
        $variantIds = array_values(array_unique(array_column($targets, 'variant_id')));

        return (int) DB::transaction(function () use ($productIds, $variantIds): int {
            if (Schema::hasTable('product_variant_overrides')) {
                DB::table('product_variant_overrides')->whereIn('product_variant_id', $variantIds)->delete();
            }
            if (Schema::hasTable('product_overrides')) {
                DB::table('product_overrides')->whereIn('product_id', $productIds)->delete();
            }
            if (Schema::hasTable('seo_meta')) {
                DB::table('seo_meta')
                    ->where('target_type', 'product')
                    ->whereIn('target_key', array_map('strval', $productIds))
                    ->delete();
            }

            return DB::table('products')->whereIn('id', $productIds)->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return 'inserted'|'updated'|'skipped'
     */
    public function upsertItem(array $item, DateTimeInterface $now, int $taxId): string
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        if ($partNo === '' || $name === '' || ! is_numeric($item['mrp'] ?? null)) {
            return 'skipped';
        }

        $group = (array) ($item['category_group'] ?? []);
        $category = (array) ($item['category'] ?? []);
        $groupId = $group['id'] ?? null;
        $categoryId = $category['id'] ?? ($item['category_id'] ?? null);
        if (! is_numeric($groupId) || ! is_numeric($categoryId)) {
            return 'skipped';
        }
        $groupId = (int) $groupId;
        $categoryId = (int) $categoryId;

        $this->upsertGroup($groupId, (string) ($group['name'] ?? 'Uncategorised'), isset($group['slug']) ? (string) $group['slug'] : null, $now);
        $this->upsertCategory(
            $categoryId,
            (string) ($category['name'] ?? 'Uncategorised'),
            isset($category['slug']) ? (string) $category['slug'] : null,
            $groupId,
            self::httpUrl($category['image'] ?? ($category['banner'] ?? null)),
            $now,
        );

        $photos = $this->photoUrls($item);
        $thumb = self::httpUrl(data_get($item, 'thumb_img.file_name'));
        $mrp = $item['mrp'];
        $stockByCode = $this->stockByCode($item);
        $brandId = $this->brandIdFor($name, $now);
        $photosJson = $photos === [] ? null : json_encode($photos, JSON_UNESCAPED_SLASHES);
        $tags = $this->tagsFor($category, $group);

        $existing = DB::table('product_variants')->where('part_no', $partNo)->first();
        if ($existing) {
            $variantId = (int) $existing->id;
            $productId = (int) $existing->product_id;

            DB::table('products')->where('id', $productId)->update([
                'name' => $name,
                'group_id' => $groupId,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'thumbnail_img' => $thumb,
                'photos' => $photosJson,
                'tags' => $tags,
                'synced_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncWarehouses($variantId, $stockByCode, $mrp, $now);

            DB::table('product_variants')->where('id', $variantId)->update([
                'part_no_normalized' => PartNumber::normalize($partNo),
                'thumbnail_img' => $thumb,
                'unit_price' => $mrp,
                'list_price' => $mrp,
                'mrp' => $mrp,
                'current_stock' => array_sum($stockByCode),
                'updated_at' => $now,
            ]);

            $this->upsertProductTax($productId, $taxId, $now);

            return 'updated';
        }

        $preferredProductId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
        $productId = ($preferredProductId !== null && $preferredProductId > 0
            && ! DB::table('products')->where('id', $preferredProductId)->exists())
            ? $preferredProductId
            : null;

        $preferredSlug = trim((string) ($item['slug'] ?? ''));
        $slug = $this->uniqueSlug('products', $preferredSlug !== '' ? $preferredSlug : Str::slug($name), $productId);

        $productRow = [
            'name' => $name,
            'slug' => $slug,
            'hsncode' => null,
            'group_id' => $groupId,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'thumbnail_img' => $thumb,
            'photos' => $photosJson,
            'description' => $name,
            'tags' => $tags,
            'published' => true,
            'approved' => true,
            'unit' => 'pc',
            'min_qty' => 1,
            'piece_per_carton' => 1,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($productId !== null) {
            DB::table('products')->insert($productRow + ['id' => $productId]);
        } else {
            $productId = (int) DB::table('products')->insertGetId($productRow);
        }

        $preferredVariantId = is_numeric($item['variant_id'] ?? null) ? (int) $item['variant_id'] : null;
        $variantId = null;
        if ($preferredVariantId !== null && $preferredVariantId > 0
            && ! DB::table('product_variants')->where('id', $preferredVariantId)->exists()) {
            $variantId = $preferredVariantId;
        } elseif (! DB::table('product_variants')->where('id', $productId)->exists()) {
            $variantId = $productId;
        }

        $variantRow = [
            'product_id' => $productId,
            'part_no' => $partNo,
            'part_no_normalized' => PartNumber::normalize($partNo),
            'options' => json_encode([]),
            'label' => null,
            'thumbnail_img' => $thumb,
            'unit_price' => $mrp,
            'list_price' => $mrp,
            'mrp' => $mrp,
            'carton_price' => null,
            'current_stock' => array_sum($stockByCode),
            'min_qty' => 1,
            'hsncode' => null,
            'is_default' => true,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($variantId !== null) {
            DB::table('product_variants')->insert($variantRow + ['id' => $variantId]);
        } else {
            $variantId = (int) DB::table('product_variants')->insertGetId($variantRow);
        }

        $this->upsertProductTax($productId, $taxId, $now);
        $this->syncWarehouses($variantId, $stockByCode, $mrp, $now);

        return 'inserted';
    }

    /**
     * Explicit-id inserts do not advance Postgres sequences; reset them (and the
     * MySQL AUTO_INCREMENT, defensively) so new rows get ids above the max.
     * SQLite AUTOINCREMENT tracks explicit ids itself.
     */
    public function syncSequences(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (['category_groups', 'categories', 'products', 'product_variants'] as $table) {
            $max = (int) DB::table($table)->max('id');
            if ($driver === 'pgsql') {
                DB::select("select setval(pg_get_serial_sequence('$table', 'id'), ?, ?)", [max(1, $max), $max > 0]);
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `$table` AUTO_INCREMENT = ".($max + 1));
            }
        }
    }

    public static function httpUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        return null;
    }

    private function upsertGroup(int $id, string $name, ?string $preferredSlug, DateTimeInterface $now): void
    {
        if (DB::table('category_groups')->where('id', $id)->exists()) {
            DB::table('category_groups')->where('id', $id)->update([
                'name' => $name,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        $base = ($preferredSlug !== null && $preferredSlug !== '') ? $preferredSlug : Str::slug($name);
        DB::table('category_groups')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $this->uniqueSlug('category_groups', $base, $id),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertCategory(int $id, string $name, ?string $preferredSlug, int $groupId, ?string $banner, DateTimeInterface $now): void
    {
        if (DB::table('categories')->where('id', $id)->exists()) {
            DB::table('categories')->where('id', $id)->update([
                'name' => $name,
                'category_group_id' => $groupId,
                'banner' => $banner,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        $base = ($preferredSlug !== null && $preferredSlug !== '') ? $preferredSlug : Str::slug($name);
        DB::table('categories')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $this->uniqueSlug('categories', $base, $id),
            'parent_id' => null,
            'category_group_id' => $groupId,
            'banner' => $banner,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertProductTax(int $productId, int $taxId, DateTimeInterface $now): void
    {
        $exists = DB::table('product_taxes')->where('product_id', $productId)->where('tax_id', $taxId)->exists();
        if ($exists) {
            DB::table('product_taxes')->where('product_id', $productId)->where('tax_id', $taxId)->update([
                'tax' => '18.00',
                'tax_type' => 'percent',
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('product_taxes')->insert([
            'product_id' => $productId,
            'tax_id' => $taxId,
            'tax' => '18.00',
            'tax_type' => 'percent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Zero warehouse locations this payload omits, then write the ones it has.
     *
     * @param  array<string, int>  $stockByCode
     */
    private function syncWarehouses(int $variantId, array $stockByCode, mixed $mrp, DateTimeInterface $now): void
    {
        $omit = DB::table('product_warehouses')->where('product_variant_id', $variantId);
        if ($stockByCode === []) {
            $omit->update(['qty' => 0, 'updated_at' => $now]);
        } else {
            $omit->whereNotIn('warehouse_code', array_keys($stockByCode))->update(['qty' => 0, 'updated_at' => $now]);
        }

        foreach ($stockByCode as $code => $qty) {
            $exists = DB::table('product_warehouses')
                ->where('product_variant_id', $variantId)
                ->where('warehouse_code', $code)
                ->exists();

            if ($exists) {
                DB::table('product_warehouses')
                    ->where('product_variant_id', $variantId)
                    ->where('warehouse_code', $code)
                    ->update(['qty' => $qty, 'price' => $mrp, 'updated_at' => $now]);
            } else {
                DB::table('product_warehouses')->insert([
                    'product_variant_id' => $variantId,
                    'warehouse_code' => $code,
                    'qty' => $qty,
                    'price' => $mrp,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function photoUrls(array $item): array
    {
        $urls = [];

        foreach ($item['images'] ?? [] as $image) {
            $url = self::httpUrl(is_array($image) ? ($image['file_name'] ?? null) : null);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, int>
     */
    private function stockByCode(array $item): array
    {
        $qtyByCode = [];

        foreach ($item['stocks'] ?? [] as $stock) {
            $name = (string) ($stock['warehouse_name'] ?? '');
            $code = self::WAREHOUSE_CODES[$name] ?? Str::upper($name);

            if ($code === '') {
                continue;
            }

            $qtyByCode[$code] = ($qtyByCode[$code] ?? 0) + (int) ($stock['qty'] ?? 0);
        }

        return $qtyByCode;
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, mixed>  $group
     */
    private function tagsFor(array $category, array $group): string
    {
        $slugs = [];
        foreach ([$category, $group] as $node) {
            $slug = trim((string) ($node['slug'] ?? ''));
            if ($slug === '') {
                $slug = Str::slug((string) ($node['name'] ?? ''));
            }
            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return implode(',', $slugs);
    }

    private function brandIdFor(string $productName, DateTimeInterface $now): int
    {
        $name = $this->brandName($productName);
        $slug = Str::slug($name);

        if (isset($this->brands[$slug])) {
            return $this->brands[$slug];
        }

        $id = DB::table('brands')->where('slug', $slug)->value('id');
        if ($id === null) {
            $id = DB::table('brands')->insertGetId([
                'name' => $name, 'slug' => $slug, 'logo' => null, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $this->brands[$slug] = (int) $id;
    }

    private function brandName(string $productName): string
    {
        $upper = Str::upper($productName);

        foreach (self::BRAND_PREFIXES as [$prefix, $display]) {
            if (str_starts_with($upper, $prefix)) {
                return $display;
            }
        }

        return 'Unbranded';
    }

    private function uniqueSlug(string $table, string $base, ?int $preferredId): string
    {
        $slug = $base !== '' ? $base : 'item';
        $taken = DB::table($table)->where('slug', $slug);
        if ($preferredId !== null) {
            $taken->where('id', '!=', $preferredId);
        }
        if (! $taken->exists()) {
            return $slug;
        }

        $suffix = $preferredId ?? Str::lower(Str::random(6));

        return $slug.'-'.$suffix;
    }
}
