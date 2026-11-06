<?php

namespace Database\Seeders;

use App\Modules\Catalog\Support\PartNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    /**
     * Longest prefix first so "Forever Gold" wins over "Forever".
     *
     * @var list<array{0: string, 1: string}>
     */
    private const BRAND_PREFIXES = [
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
    private const WAREHOUSE_CODES = [
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

    /**
     * Original B2B AIR BLOWER catalog (pages 1–3) until the product feed exists.
     *
     * SAFE by default: upserts by the FIXED ids in the fixture (B2B base columns
     * only), inserts missing rows, never deletes, never touches *_overrides or
     * seo_meta. Aborts before writing if a fixed id is already used by a row with
     * a different natural key (variants/products by part_no, categories/groups
     * by slug). Full delete + reload: resetAndReload() (catalog:reset-seed).
     */
    public function run(): void
    {
        $items = $this->products();
        [$groups, $categories] = $this->taxonomy($items);

        $this->assertFixedIds($items);
        $this->assertNaturalKeysMatch($groups, $categories, $items);

        DB::transaction(fn () => $this->writeCatalog($items, $groups, $categories));

        $this->syncSequences();
    }

    /**
     * Upsert the B2B base rows (runs inside the caller's transaction).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, array<string, mixed>>  $categories
     */
    protected function writeCatalog(array $items, array $groups, array $categories): void
    {
        $now = now();
        $taxId = $this->upsertTax($now);

        DB::table('category_groups')->upsert(array_map(fn (array $g): array => [
            'id' => $g['id'],
            'name' => $g['name'],
            'slug' => $g['slug'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($groups)), ['id'], ['name', 'slug', 'is_active', 'updated_at']);

        DB::table('categories')->upsert(array_map(fn (array $c): array => [
            'id' => $c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'parent_id' => $c['parent_id'],
            'category_group_id' => $c['category_group_id'],
            'banner' => $c['banner'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($categories)), ['id'], ['name', 'slug', 'parent_id', 'category_group_id', 'banner', 'is_active', 'updated_at']);

        foreach ($items as $item) {
            $this->upsertProduct($item, $taxId, $now);
        }
    }

    /**
     * Full reset: delete the B2B catalog tables, then run(). Override tables and
     * seo_meta are never deleted. Refused unless CATALOG_SEED_ALLOW_RESET=true.
     */
    public function resetAndReload(): void
    {
        $blockers = self::resetBlockers();
        if ($blockers !== []) {
            throw new RuntimeException("Catalog full reset refused (nothing was deleted):\n - ".implode("\n - ", $blockers)
                ."\nPlain `php artisan db:seed --class=CatalogSeeder` is safe (upsert by fixed id, no deletes).");
        }

        $items = $this->products();
        [$groups, $categories] = $this->taxonomy($items);
        $this->assertFixedIds($items);

        // DELETE (not TRUNCATE: MySQL TRUNCATE commits implicitly) + reload in ONE
        // transaction, so any failure rolls the catalog back to what it was.
        // FK checks are switched off for the session first (outside the transaction:
        // SQLite ignores the pragma inside one) so cart / wishlist rows are not
        // cascade-deleted; the same ids come back. Postgres: constraints are only
        // deferred there, so cascades would still fire (not a target DB today).
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($items, $groups, $categories): void {
                $this->deleteCatalog();
                $this->writeCatalog($items, $groups, $categories);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Sequence / AUTO_INCREMENT reset after commit (MySQL ALTER commits implicitly).
        $this->syncSequences();
    }

    /**
     * Why a full reset is not allowed right now ([] = allowed).
     *
     * @return list<string>
     */
    public static function resetBlockers(): array
    {
        if ((bool) config('catalog.seed_allow_reset', false)) {
            return [];
        }

        $reasons = ['CATALOG_SEED_ALLOW_RESET is not true (required for any full reset).'];

        if (app()->isProduction()) {
            $reasons[] = 'APP_ENV is production.';
        }

        $overrides = 0;
        foreach (['category_group_overrides', 'category_overrides', 'product_overrides', 'product_variant_overrides'] as $table) {
            if (Schema::hasTable($table)) {
                $overrides += DB::table($table)->count();
            }
        }
        if ($overrides > 0) {
            $reasons[] = "$overrides catalog override row(s) exist (keyed by catalog id).";
        }

        if (Schema::hasTable('seo_meta') && ($seo = DB::table('seo_meta')->count()) > 0) {
            $reasons[] = "$seo seo_meta row(s) exist (product/category SEO is keyed by catalog id).";
        }

        $orders = Schema::hasTable('orders') ? DB::table('orders')->count() : 0;
        $lines = Schema::hasTable('order_items') ? DB::table('order_items')->count() : 0;
        if ($orders > 0 || $lines > 0) {
            $reasons[] = "$orders order(s) / $lines order line(s) exist (order lines reference product/variant ids).";
        }

        return $reasons;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        $path = database_path('seeders/data/b2b-air-blowers.json');
        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Catalog fixture must be a JSON array.');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function taxonomy(array $items): array
    {
        $groups = [];
        $categories = [];

        foreach ($items as $item) {
            $g = (array) ($item['category_group'] ?? []);
            $c = (array) ($item['category'] ?? []);
            foreach ([[$g, 'category_group'], [$c, 'category']] as [$node, $label]) {
                if (! is_int($node['id'] ?? null) || ! is_string($node['slug'] ?? null) || $node['slug'] === '') {
                    throw new RuntimeException("CatalogSeeder: fixture item {$item['part_no']} has no fixed id/slug for its $label.");
                }
            }

            $groups[$g['id']] = ['id' => $g['id'], 'name' => (string) $g['name'], 'slug' => $g['slug']];
            $categories[$c['id']] = [
                'id' => $c['id'],
                'name' => (string) $c['name'],
                'slug' => $c['slug'],
                'parent_id' => isset($c['parent_id']) ? (int) $c['parent_id'] : null,
                'category_group_id' => $g['id'],
                'banner' => $this->httpUrl($c['image'] ?? null),
            ];
        }

        return [$groups, $categories];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertFixedIds(array $items): void
    {
        $productIds = [];
        $variantIds = [];
        foreach ($items as $item) {
            if (! is_int($item['id'] ?? null) || ! is_int($item['variant_id'] ?? null)) {
                throw new RuntimeException("CatalogSeeder: fixture item {$item['part_no']} is missing a fixed id / variant_id.");
            }
            if (isset($productIds[$item['id']]) || isset($variantIds[$item['variant_id']])) {
                throw new RuntimeException("CatalogSeeder: duplicate fixed id in fixture (item {$item['part_no']}).");
            }
            $productIds[$item['id']] = true;
            $variantIds[$item['variant_id']] = true;
        }
    }

    /**
     * Abort BEFORE any write when a fixed id is held by a different natural key,
     * or a natural key already lives under a different id.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $items
     */
    private function assertNaturalKeysMatch(array $groups, array $categories, array $items): void
    {
        $problems = [];

        foreach (['category_groups' => $groups, 'categories' => $categories] as $table => $rows) {
            foreach ($rows as $row) {
                $slugAtId = DB::table($table)->where('id', $row['id'])->value('slug');
                if ($slugAtId !== null && $slugAtId !== $row['slug']) {
                    $problems[] = "$table id {$row['id']} is '$slugAtId' in the DB but '{$row['slug']}' in the fixture.";
                }
                $idForSlug = DB::table($table)->where('slug', $row['slug'])->where('id', '!=', $row['id'])->value('id');
                if ($idForSlug !== null) {
                    $problems[] = "$table slug '{$row['slug']}' is id $idForSlug in the DB but {$row['id']} in the fixture.";
                }
            }
        }

        foreach ($items as $item) {
            $partNo = (string) $item['part_no'];

            $variant = DB::table('product_variants')->where('id', $item['variant_id'])->first(['part_no', 'product_id']);
            if ($variant !== null && $variant->part_no !== $partNo) {
                $problems[] = "product_variants id {$item['variant_id']} is part_no {$variant->part_no} in the DB but $partNo in the fixture.";
            } elseif ($variant !== null && (int) $variant->product_id !== $item['id']) {
                $problems[] = "part_no $partNo belongs to product {$variant->product_id} in the DB but {$item['id']} in the fixture.";
            }

            $idForPart = DB::table('product_variants')->where('part_no', $partNo)->where('id', '!=', $item['variant_id'])->value('id');
            if ($idForPart !== null) {
                $problems[] = "part_no $partNo is variant id $idForPart in the DB but {$item['variant_id']} in the fixture.";
            }

            if (DB::table('products')->where('id', $item['id'])->exists()) {
                $parts = DB::table('product_variants')->where('product_id', $item['id'])->pluck('part_no')->all();
                if ($parts !== [] && ! in_array($partNo, $parts, true)) {
                    $problems[] = "products id {$item['id']} has part_no(s) ".implode(', ', $parts)." in the DB but $partNo in the fixture.";
                }
            }

            $idForSlug = DB::table('products')->where('slug', $item['slug'])->where('id', '!=', $item['id'])->value('id');
            if ($idForSlug !== null) {
                $problems[] = "products slug '{$item['slug']}' is id $idForSlug in the DB but {$item['id']} in the fixture.";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("CatalogSeeder aborted before writing anything: fixed ids do not match the DB.\n - "
                .implode("\n - ", array_values(array_unique($problems)))
                ."\nRefresh the fixture ids with `php artisan catalog:apply-seed-ids <export.json>`.");
        }
    }

    private function upsertTax(\DateTimeInterface $now): int
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
     * B2B base columns only. Never touches override tables.
     *
     * @param  array<string, mixed>  $item
     */
    private function upsertProduct(array $item, int $taxId, \DateTimeInterface $now): void
    {
        $photos = $this->photoUrls($item);
        $thumb = $this->httpUrl(data_get($item, 'thumb_img.file_name'));
        $mrp = $item['mrp'];
        $stockByCode = $this->stockByCode($item);
        $brandId = $this->brandIdFor((string) $item['name'], $now);
        $productId = (int) $item['id'];
        $variantId = (int) $item['variant_id'];

        $productColumns = [
            'name', 'slug', 'hsncode', 'group_id', 'category_id', 'brand_id', 'thumbnail_img', 'photos',
            'description', 'tags', 'published', 'approved', 'unit', 'min_qty', 'piece_per_carton', 'synced_at', 'updated_at',
        ];
        DB::table('products')->upsert([[
            'id' => $productId,
            'name' => $item['name'],
            'slug' => $item['slug'],
            'hsncode' => null,
            'group_id' => (int) $item['category_group']['id'],
            'category_id' => (int) $item['category']['id'],
            'brand_id' => $brandId,
            'thumbnail_img' => $thumb,
            'photos' => $photos === [] ? null : json_encode($photos, JSON_UNESCAPED_SLASHES),
            'description' => $item['name'],
            'tags' => 'air-blower,power-tools',
            'published' => true,
            'approved' => true,
            'unit' => 'pc',
            'min_qty' => 1,
            'piece_per_carton' => 1,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], $productColumns);

        DB::table('product_taxes')->upsert([[
            'product_id' => $productId,
            'tax_id' => $taxId,
            'tax' => '18.00',
            'tax_type' => 'percent',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['product_id', 'tax_id'], ['tax', 'tax_type', 'updated_at']);

        $variantColumns = [
            'product_id', 'part_no', 'part_no_normalized', 'options', 'label', 'thumbnail_img', 'unit_price',
            'list_price', 'mrp', 'carton_price', 'current_stock', 'min_qty', 'hsncode', 'is_default', 'position', 'updated_at',
        ];
        DB::table('product_variants')->upsert([[
            'id' => $variantId,
            'product_id' => $productId,
            'part_no' => $item['part_no'],
            'part_no_normalized' => PartNumber::normalize((string) $item['part_no']),
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
        ]], ['id'], $variantColumns);

        foreach ($stockByCode as $code => $qty) {
            DB::table('product_warehouses')->upsert([[
                'product_variant_id' => $variantId,
                'warehouse_code' => $code,
                'qty' => $qty,
                'price' => $mrp,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['product_variant_id', 'warehouse_code'], ['qty', 'price', 'updated_at']);
        }
    }

    /**
     * Explicit-id inserts do not advance Postgres sequences; reset them (and the
     * MySQL AUTO_INCREMENT, defensively) so new rows get ids above the max.
     * SQLite AUTOINCREMENT tracks explicit ids itself.
     */
    private function syncSequences(): void
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

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function photoUrls(array $item): array
    {
        $urls = [];

        foreach ($item['images'] ?? [] as $image) {
            $url = $this->httpUrl($image['file_name'] ?? null);

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

    private function brandIdFor(string $productName, \DateTimeInterface $now): int
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

    private function httpUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        return null;
    }

    /** Only reachable through resetAndReload() (guarded); runs inside its transaction. */
    private function deleteCatalog(): void
    {
        DB::table('categories')->update(['parent_id' => null]); // self-reference first

        foreach ([
            'product_warehouses',
            'product_taxes',
            'product_variants',
            'products',
            'taxes',
            'brands',
            'categories',
            'category_groups',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
