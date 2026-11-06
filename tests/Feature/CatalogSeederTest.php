<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Overrides\ProductOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Support\OrderTimeline;
use App\Modules\Seo\Models\SeoMeta;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function idMap(): array
    {
        return [
            'groups' => DB::table('category_groups')->orderBy('id')->pluck('slug', 'id')->all(),
            'categories' => DB::table('categories')->orderBy('id')->pluck('slug', 'id')->all(),
            'products' => DB::table('products')->orderBy('id')->pluck('slug', 'id')->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->get(['id', 'product_id', 'part_no'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_fixture_has_fixed_ids_matching_insertion_order(): void
    {
        $items = json_decode((string) file_get_contents(database_path('seeders/data/b2b-air-blowers.json')), true);
        $this->assertSame(range(1, count($items)), array_column($items, 'id'));
        $this->assertSame(range(1, count($items)), array_column($items, 'variant_id'));
        $this->assertSame([1], array_values(array_unique(array_map(fn ($i) => $i['category']['id'], $items))));
        $this->assertSame(['air-blower'], array_values(array_unique(array_map(fn ($i) => $i['category']['slug'], $items))));
        $this->assertSame(['power-tools'], array_values(array_unique(array_map(fn ($i) => $i['category_group']['slug'], $items))));
    }

    public function test_seeding_twice_keeps_ids_and_leaves_overrides_and_seo_meta_untouched(): void
    {
        $this->seed(CatalogSeeder::class);
        $before = $this->idMap();
        $this->assertSame('MZ11018', $before['variants'][0]['part_no']);
        $this->assertSame(1, $before['variants'][0]['id']);

        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Admin name', 'hidden' => true, 'version' => 3]);
        ProductVariantOverride::query()->create(['product_variant_id' => 1, 'unit_price' => '999.00', 'current_stock' => 1]);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => '1', 'seo_title' => 'Kept', 'version' => 2]);
        $overrideRows = DB::table('product_overrides')->get()->toArray();
        $variantOverrideRows = DB::table('product_variant_overrides')->get()->toArray();
        $seoRows = DB::table('seo_meta')->get()->toArray();

        // B2B side drifted (e.g. local edit) → reseed restores the base, by id.
        DB::table('products')->where('id', 1)->update(['name' => 'Drifted']);
        $this->seed(CatalogSeeder::class);

        $this->assertSame($before, $this->idMap());
        $this->assertSame(45, Product::query()->count());
        $this->assertSame(45, ProductVariant::query()->count());
        $this->assertEquals($overrideRows, DB::table('product_overrides')->get()->toArray());
        $this->assertEquals($variantOverrideRows, DB::table('product_variant_overrides')->get()->toArray());
        $this->assertEquals($seoRows, DB::table('seo_meta')->get()->toArray());
        $this->assertSame('OPEL 5332 - ELECTRIC BLOWER 600WATTS', DB::table('products')->where('id', 1)->value('name'));
    }

    public function test_override_survives_reseed_and_still_applies(): void
    {
        $this->seed(CatalogSeeder::class);
        ProductOverride::query()->create(['product_id' => 2, 'name' => 'Admin Blower']);
        ProductVariantOverride::query()->create(['product_variant_id' => 2, 'unit_price' => '111.00']);

        $this->seed(CatalogSeeder::class);

        $this->getJson('/api/v1/products/2')->assertOk()
            ->assertJsonPath('data.name', 'Admin Blower')
            ->assertJsonPath('data.variants.0.unit_price', '111.00')
            ->assertJsonPath('data.variants.0.part_no', 'MZ14874');
    }

    public function test_new_rows_after_seeding_get_ids_above_the_seeded_max(): void
    {
        $this->seed(CatalogSeeder::class);

        $group = CategoryGroup::factory()->create();
        $category = Category::factory()->create(['category_group_id' => $group->id]);
        $product = Product::factory()->create(['group_id' => $group->id, 'category_id' => $category->id]);

        $this->assertGreaterThan(1, $group->id);
        $this->assertGreaterThan(1, $category->id);
        $this->assertGreaterThan(45, $product->id);
        $this->assertGreaterThan(45, $product->defaultVariant->id);
    }

    public function test_natural_key_mismatch_aborts_before_writing(): void
    {
        $group = CategoryGroup::factory()->create(['slug' => 'power-tools']);
        $category = Category::factory()->create(['slug' => 'air-blower', 'category_group_id' => $group->id]);
        $product = Product::factory()->create(['name' => 'Local only', 'group_id' => $group->id, 'category_id' => $category->id]);
        $this->assertSame(1, $product->id);
        $product->defaultVariant->update(['part_no' => 'NOT-MZ11018']);

        try {
            $this->seed(CatalogSeeder::class);
            $this->fail('Seeder should have aborted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('aborted before writing anything', $e->getMessage());
            $this->assertStringContainsString('NOT-MZ11018', $e->getMessage());
        }

        $this->assertSame(1, Product::query()->count());
        $this->assertSame('Local only', DB::table('products')->where('id', 1)->value('name'));
        $this->assertSame(0, DB::table('taxes')->count());
    }

    public function test_category_slug_mismatch_aborts(): void
    {
        $group = CategoryGroup::factory()->create(['slug' => 'power-tools']);
        Category::factory()->create(['slug' => 'something-else', 'category_group_id' => $group->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("categories id 1 is 'something-else'");
        $this->seed(CatalogSeeder::class);
    }

    // ---- full reset path (guarded) -----------------------------------------

    private function assertResetRefused(string $reason): void
    {
        $count = Product::query()->count();
        try {
            (new CatalogSeeder)->resetAndReload();
            $this->fail('Reset should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refused (nothing was deleted)', $e->getMessage());
            $this->assertStringContainsString($reason, $e->getMessage());
        }
        $this->assertSame($count, Product::query()->count());
    }

    public function test_reset_refused_without_env_flag(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->assertResetRefused('CATALOG_SEED_ALLOW_RESET is not true');
        $this->artisan('catalog:reset-seed')->assertFailed();
    }

    public function test_reset_refused_when_overrides_exist(): void
    {
        $this->seed(CatalogSeeder::class);
        ProductOverride::query()->create(['product_id' => 1, 'name' => 'x']);
        $this->assertResetRefused('1 catalog override row(s) exist');
    }

    public function test_reset_refused_when_seo_meta_exists(): void
    {
        $this->seed(CatalogSeeder::class);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => '1', 'version' => 1]);
        $this->assertResetRefused('1 seo_meta row(s) exist');
    }

    public function test_reset_refused_when_orders_exist(): void
    {
        $this->seed(CatalogSeeder::class);
        $user = User::factory()->create();
        Order::query()->create([
            'user_id' => $user->id, 'number' => 'OPL-SEEDGUARD', 'status' => 'processing',
            'shipping_name' => 'A', 'shipping_phone' => '919876543210', 'shipping_line1' => '1',
            'shipping_city' => 'Pune', 'shipping_state' => 'MH', 'shipping_pincode' => '411001',
            'item_count' => 1, 'subtotal' => 1, 'shipping_fee' => 0, 'grand_total' => 1, 'currency' => 'INR',
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'timeline' => OrderTimeline::forStatus('processing', now()),
        ]);
        $this->assertResetRefused('1 order(s) / 0 order line(s) exist');
    }

    public function test_reset_refused_in_production(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->app['env'] = 'production';
        $this->assertResetRefused('APP_ENV is production');
    }

    public function test_reset_runs_with_env_flag_and_keeps_overrides(): void
    {
        $this->seed(CatalogSeeder::class);
        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Kept']);
        DB::table('products')->where('id', 1)->update(['name' => 'Drifted']);
        config(['catalog.seed_allow_reset' => true]);

        $this->artisan('catalog:reset-seed')->assertSuccessful();

        $this->assertSame(45, Product::query()->count());
        $this->assertSame('OPEL 5332 - ELECTRIC BLOWER 600WATTS', DB::table('products')->where('id', 1)->value('name'));
        $this->assertSame('Kept', ProductOverride::query()->value('name'));
        $this->assertSame('Kept', Product::query()->find(1)->name);
    }

    public function test_reset_rolls_back_when_reload_fails(): void
    {
        $this->seed(CatalogSeeder::class);
        DB::table('products')->where('id', 1)->update(['name' => 'Before reset']);
        config(['catalog.seed_allow_reset' => true]);

        $failing = new class extends CatalogSeeder
        {
            protected function writeCatalog(array $items, array $groups, array $categories): void
            {
                parent::writeCatalog(array_slice($items, 0, 3), $groups, $categories);
                throw new RuntimeException('boom mid-reload');
            }
        };

        try {
            $failing->resetAndReload();
            $this->fail('Expected failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom mid-reload', $e->getMessage());
        }

        // Deletes were rolled back together with the partial reload.
        $this->assertSame(45, Product::query()->count());
        $this->assertSame(45, ProductVariant::query()->count());
        $this->assertSame('Before reset', DB::table('products')->where('id', 1)->value('name'));
    }

    // ---- id export / apply tooling -------------------------------------------

    public function test_export_ids_round_trips_through_apply_seed_ids(): void
    {
        $this->seed(CatalogSeeder::class);
        $dir = storage_path('framework/testing/catalog-ids');
        @mkdir($dir, 0755, true);
        $export = $dir.'/export.json';
        $fixture = $dir.'/fixture.json';

        $this->artisan('catalog:export-ids', ['--output' => $export])->assertSuccessful();
        $dump = json_decode((string) file_get_contents($export), true);
        $this->assertCount(45, $dump['variants']);
        $this->assertSame(['id' => 1, 'product_id' => 1, 'part_no' => 'MZ11018'], $dump['variants'][0]);

        // Pretend prod has different ids: shift every product/variant id by 100.
        foreach ($dump['variants'] as &$v) {
            $v['id'] += 100;
            $v['product_id'] += 100;
        }
        unset($v);
        file_put_contents($export, json_encode($dump));
        copy(database_path('seeders/data/b2b-air-blowers.json'), $fixture);

        $home = getenv('HOME');
        putenv('HOME='.$dir); // keep the command's fixture backup inside the test dir
        try {
            $this->artisan('catalog:apply-seed-ids', ['export' => $export, '--fixture' => $fixture])->assertSuccessful();
        } finally {
            putenv($home === false ? 'HOME' : 'HOME='.$home);
        }

        $items = json_decode((string) file_get_contents($fixture), true);
        $this->assertSame(101, $items[0]['id']);
        $this->assertSame(101, $items[0]['variant_id']);
        $this->assertSame('MZ11018', $items[0]['part_no']);
        $this->assertSame(1, $items[0]['category']['id']);

        // Missing part_no in export → nothing written without --assign-missing.
        array_pop($dump['variants']);
        file_put_contents($export, json_encode($dump));
        $before = file_get_contents($fixture);
        $this->artisan('catalog:apply-seed-ids', ['export' => $export, '--fixture' => $fixture])->assertFailed();
        $this->assertSame($before, file_get_contents($fixture));

        foreach (glob($dir.'/{,opel_backups/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
    }
}
