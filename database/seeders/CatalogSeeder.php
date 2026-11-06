<?php

namespace Database\Seeders;

use App\Modules\Catalog\Services\B2bCatalogSync;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    /**
     * Test / fixture loader for the original 45 air blowers (fixed ids).
     *
     * Live catalog updates go through catalog:sync-b2b. This seeder is kept so
     * CatalogTest and CatalogSeederTest can load the fixture. It upserts via
     * B2bCatalogSync (match by part_no), never deletes, never touches overrides
     * or seo_meta. Aborts before writing if a fixed id is already used by a row
     * with a different natural key. Full delete + reload: resetAndReload().
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
        $sync = app(B2bCatalogSync::class);
        $taxId = $sync->upsertTax($now);

        foreach ($items as $item) {
            $sync->upsertItem($item, $now, $taxId);
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
                'banner' => B2bCatalogSync::httpUrl($c['image'] ?? null),
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

    private function syncSequences(): void
    {
        app(B2bCatalogSync::class)->syncSequences();
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
