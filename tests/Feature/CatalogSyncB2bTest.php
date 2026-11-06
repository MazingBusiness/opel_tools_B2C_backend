<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Overrides\ProductOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Support\OrderTimeline;
use App\Modules\Seo\Models\SeoMeta;
use App\Modules\Seo\Support\SeoCache;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogSyncB2bTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'catalog.b2b.products_url' => 'https://mazingbusiness.com/mazing_business_react/api/product/products',
            'catalog.b2b.products_user_id' => '24185',
            'catalog.b2b.retry' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'id' => 9520,
            'part_no' => 'MZ11018',
            'name' => 'OPEL 5332 - ELECTRIC BLOWER 600WATTS',
            'slug' => 'opel-5332---electric-blower-600watt',
            'mrp' => 1055,
            'discount_price' => 801.80,
            'product_price' => 1055.16,
            'bulk_discount_price' => 785.76,
            'thumb_img' => ['file_name' => 'https://mazingbusiness.com/public/uploads/all/thumb_/x.jpg'],
            'images' => [['file_name' => 'https://mazingbusiness.com/public/uploads/all/x.jpg']],
            'stocks' => [
                ['warehouse_name' => 'Delhi', 'qty' => 12],
                ['warehouse_name' => 'Mumbai', 'qty' => 348],
                ['warehouse_name' => 'Kolkata', 'qty' => 12],
            ],
            'category_group' => ['id' => 1, 'name' => 'POWER TOOLS', 'slug' => 'power-tools'],
            'category' => ['id' => 1, 'name' => 'AIR BLOWER', 'slug' => 'air-blower', 'image' => null],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function page(array $items, int $page = 1, int $last = 1): array
    {
        return [
            'res' => true,
            'data' => [
                'current_page' => $page,
                'data' => $items,
                'last_page' => $last,
                'total' => count($items),
                'next_page_url' => $page < $last
                    ? 'https://mazingbusiness.com/mazing_business_react/api/product/products?page='.($page + 1)
                    : null,
                'first_page_url' => 'https://mazingbusiness.com/mazing_business_react/api/product/products?page=1',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|int>  $pagesByNumber
     */
    private function fakePages(array $pagesByNumber): void
    {
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($pagesByNumber) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $this->assertSame('24185', (string) ($query['user_id'] ?? ''), 'every request must send user_id');
            $page = (int) ($query['page'] ?? 0);
            $payload = $pagesByNumber[$page] ?? 500;
            if (is_int($payload)) {
                return Http::response(['res' => false], $payload);
            }

            return Http::response($payload);
        });
    }

    public function test_existing_seeded_part_no_keeps_local_id_and_admin_override(): void
    {
        $this->seed(CatalogSeeder::class);
        $originalSlug = DB::table('products')->where('id', 1)->value('slug');
        DB::table('products')->where('id', 1)->update(['description' => 'Keep me', 'published' => false]);

        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Admin name']);
        ProductVariantOverride::query()->create(['product_variant_id' => 1, 'unit_price' => '999.00']);

        $this->fakePages([
            1 => $this->page([
                $this->item([
                    'id' => 9520,
                    'name' => 'NEW BLOWER NAME',
                    'slug' => 'would-overwrite-slug',
                    'mrp' => 2000,
                    'discount_price' => 1,
                ]),
            ]),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $product = DB::table('products')->where('id', 1)->first();
        $variant = DB::table('product_variants')->where('id', 1)->first();
        $this->assertSame('MZ11018', $variant->part_no);
        $this->assertSame(1, (int) $variant->product_id);
        $this->assertSame($originalSlug, $product->slug);
        $this->assertSame('NEW BLOWER NAME', $product->name);
        $this->assertSame('Keep me', $product->description);
        $this->assertSame(0, (int) $product->published);
        $this->assertEqualsWithDelta(2000.0, (float) $variant->unit_price, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $variant->mrp, 0.001);
        $this->assertSame(0, DB::table('product_variants')->where('part_no', 'MZ11018')->where('id', '!=', 1)->count());
        $this->assertNull(Product::query()->find(9520));
        $this->assertSame('Admin name', Product::query()->find(1)->name);
        $this->assertSame('999.00', (string) ProductVariant::query()->find(1)->unit_price);
    }

    public function test_new_part_no_inserts_with_b2b_id_when_free(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->fakePages([
            1 => $this->page([
                $this->item([
                    'id' => 56001,
                    'part_no' => 'MZ99999',
                    'name' => 'OPEL NEW STEEL BAR',
                    'slug' => 'opel-new-steel-bar',
                    'mrp' => 1115.13,
                    'variant_id' => null,
                    'category_group' => ['id' => 8, 'name' => 'HARDWARE'],
                    'category' => ['id' => 80, 'name' => 'STEEL BAR'],
                    'stocks' => [['warehouse_name' => 'Delhi', 'qty' => 4]],
                ]),
            ]),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $product = Product::query()->where('slug', 'opel-new-steel-bar')->first();
        $this->assertNotNull($product);
        $this->assertSame(56001, $product->id);
        $this->assertSame(1, (int) DB::table('products')->where('id', 56001)->value('published'));
        $this->assertSame('OPEL NEW STEEL BAR', DB::table('products')->where('id', 56001)->value('description'));
        $this->assertSame('steel-bar,hardware', DB::table('products')->where('id', 56001)->value('tags'));
        $this->assertSame(56001, (int) DB::table('product_variants')->where('part_no', 'MZ99999')->value('id'));
        $this->assertEqualsWithDelta(1115.13, (float) DB::table('product_variants')->where('part_no', 'MZ99999')->value('unit_price'), 0.001);
        $this->assertEqualsWithDelta(1226.64, (float) ProductVariant::query()->where('part_no', 'MZ99999')->first()->unit_price, 0.001);
    }

    public function test_new_part_no_does_not_steal_an_existing_id(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->fakePages([
            1 => $this->page([
                $this->item([
                    'id' => 1,
                    'part_no' => 'MZ-NEW-1',
                    'name' => 'OPEL BRAND NEW SKU',
                    'slug' => 'opel-brand-new-sku',
                    'mrp' => 100,
                    'stocks' => [['warehouse_name' => 'Delhi', 'qty' => 1]],
                ]),
            ]),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame('MZ11018', DB::table('product_variants')->where('id', 1)->value('part_no'));
        $this->assertSame(1, (int) DB::table('products')->where('id', 1)->value('id'));
        $created = DB::table('product_variants')->where('part_no', 'MZ-NEW-1')->first();
        $this->assertNotNull($created);
        $this->assertGreaterThan(45, (int) $created->id);
        $this->assertGreaterThan(45, (int) $created->product_id);
        $this->assertNotSame(1, (int) $created->product_id);
    }

    public function test_failed_page_does_not_unpublish_anything(): void
    {
        $this->seed(CatalogSeeder::class);
        $before = DB::table('products')->orderBy('id')->get(['id', 'published', 'name'])->keyBy('id');
        $stockTwo = (int) DB::table('product_variants')->where('id', 2)->value('current_stock');

        $this->fakePages([
            1 => $this->page([$this->item(['mrp' => 2000])], 1, 2),
            2 => 500,
        ]);

        $this->artisan('catalog:sync-b2b')->assertFailed();

        $this->assertSame(45, Product::query()->count());
        $this->assertSame($stockTwo, (int) DB::table('product_variants')->where('id', 2)->value('current_stock'));
        foreach ($before as $id => $row) {
            $this->assertSame((int) $row->published, (int) DB::table('products')->where('id', $id)->value('published'), "product {$id} published flipped");
        }
    }

    public function test_omitted_warehouse_goes_to_zero(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->assertSame(3, DB::table('product_warehouses')->where('product_variant_id', 1)->count());

        $this->fakePages([
            1 => $this->page([
                $this->item([
                    'stocks' => [['warehouse_name' => 'Delhi', 'qty' => 10]],
                ]),
            ]),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $rows = DB::table('product_warehouses')->where('product_variant_id', 1)->pluck('qty', 'warehouse_code')->all();
        $this->assertSame(10, (int) $rows['DELHI']);
        $this->assertSame(0, (int) $rows['MUMBAI']);
        $this->assertSame(0, (int) $rows['KOLKATA']);
        $this->assertSame(10, (int) DB::table('product_variants')->where('id', 1)->value('current_stock'));
    }

    public function test_skips_a_row_with_no_part_no(): void
    {
        $this->fakePages([
            1 => $this->page([
                $this->item(['id' => 99, 'part_no' => '', 'name' => 'NO SKU']),
                $this->item([
                    'id' => 70000,
                    'part_no' => 'MZ-OK',
                    'name' => 'OPEL OK',
                    'slug' => 'opel-ok',
                    'mrp' => 10,
                    'stocks' => [['warehouse_name' => 'Delhi', 'qty' => 1]],
                ]),
            ]),
        ]);

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('Skipped a row (no part_no)')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('products')->where('id', 99)->count());
        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-OK')->count());
    }

    public function test_does_not_follow_next_page_url(): void
    {
        $this->fakePages([
            1 => $this->page([$this->item(['id' => 80000, 'part_no' => 'MZ-ONLY', 'slug' => 'mz-only', 'name' => 'OPEL ONLY'])], 1, 1),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return (int) ($query['page'] ?? 0) === 1 && (string) ($query['user_id'] ?? '') === '24185';
        });
    }

    public function test_successful_sync_bumps_catalog_and_seo_cache(): void
    {
        $catalogBefore = CatalogCache::version();
        $seoBefore = SeoCache::version();

        $this->fakePages([
            1 => $this->page([$this->item(['id' => 80000, 'part_no' => 'MZ-ONLY', 'slug' => 'mz-only', 'name' => 'OPEL ONLY'])]),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame($catalogBefore + 1, CatalogCache::version());
        $this->assertSame($seoBefore + 1, SeoCache::version());
    }

    public function test_failed_later_page_still_bumps_catalog_cache(): void
    {
        $this->seed(CatalogSeeder::class);
        $before = CatalogCache::version();

        $this->fakePages([
            1 => $this->page([$this->item(['mrp' => 2000])], 1, 2),
            2 => 500,
        ]);

        $this->artisan('catalog:sync-b2b')->assertFailed();

        $this->assertSame($before + 1, CatalogCache::version());
        $this->assertEqualsWithDelta(2000.0, (float) DB::table('product_variants')->where('id', 1)->value('unit_price'), 0.001);
    }

    public function test_failed_first_page_does_not_bump_catalog_cache(): void
    {
        $before = CatalogCache::version();
        $this->fakePages([1 => 500]);

        $this->artisan('catalog:sync-b2b')->assertFailed();

        $this->assertSame($before, CatalogCache::version());
        $this->assertSame(0, Product::query()->count());
    }

    public function test_wipe_demo_seed_lets_sync_insert_live_b2b_ids(): void
    {
        $this->seed(CatalogSeeder::class);
        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Orphan me']);
        ProductVariantOverride::query()->create(['product_variant_id' => 1, 'unit_price' => '999.00']);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => '1', 'seo_title' => 'Old', 'version' => 1]);
        $this->assertSame(1, (int) DB::table('product_variants')->where('part_no', 'MZ11018')->value('id'));
        $this->assertSame(1, DB::table('category_groups')->count());

        $this->fakePages([
            1 => $this->page([$this->item(['id' => 9520, 'mrp' => 1055])]),
        ]);

        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();

        $variant = DB::table('product_variants')->where('part_no', 'MZ11018')->first();
        $this->assertNotNull($variant);
        $this->assertSame(9520, (int) $variant->id);
        $this->assertSame(9520, (int) $variant->product_id);
        $this->assertSame(0, DB::table('products')->where('id', 1)->count());
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, ProductOverride::query()->count());
        $this->assertSame(0, ProductVariantOverride::query()->count());
        $this->assertSame(0, SeoMeta::query()->where('target_type', 'product')->count());
        $this->assertSame(1, DB::table('category_groups')->count());
        $this->assertSame(1, DB::table('categories')->count());
    }

    public function test_wipe_demo_seed_leaves_orders_and_categories_in_place(): void
    {
        $this->seed(CatalogSeeder::class);
        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id, 'number' => 'OPL-WIPEGUARD', 'status' => 'processing',
            'shipping_name' => 'A', 'shipping_phone' => '919876543210', 'shipping_line1' => '1',
            'shipping_city' => 'Pune', 'shipping_state' => 'MH', 'shipping_pincode' => '411001',
            'item_count' => 1, 'subtotal' => 1, 'shipping_fee' => 0, 'grand_total' => 1, 'currency' => 'INR',
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'timeline' => OrderTimeline::forStatus('processing', now()),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => 1,
            'variant_id' => 1,
            'title' => 'Seeded blower',
            'unit_price' => 1,
            'qty' => 1,
            'line_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakePages([
            1 => $this->page([$this->item(['id' => 9520])]),
        ]);

        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();

        $this->assertSame(9520, (int) DB::table('product_variants')->where('part_no', 'MZ11018')->value('id'));
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, (int) DB::table('order_items')->where('order_id', $order->id)->where('product_id', 1)->where('variant_id', 1)->count());
        $this->assertSame(1, DB::table('category_groups')->count());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_second_wipe_leaves_live_b2b_ids_and_their_overrides(): void
    {
        $this->seed(CatalogSeeder::class);
        $feed = [1 => $this->page([$this->item(['id' => 9520, 'mrp' => 1055])])];
        $this->fakePages($feed);
        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();
        $this->assertSame(9520, (int) DB::table('product_variants')->where('part_no', 'MZ11018')->value('id'));

        ProductOverride::query()->create(['product_id' => 9520, 'name' => 'Keep me']);
        ProductVariantOverride::query()->create(['product_variant_id' => 9520, 'unit_price' => '888.00']);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => '9520', 'seo_title' => 'Keep SEO', 'version' => 1]);

        $this->fakePages($feed);
        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();

        $this->assertSame(9520, (int) DB::table('product_variants')->where('part_no', 'MZ11018')->value('id'));
        $this->assertSame(1, Product::query()->count());
        $this->assertSame('Keep me', ProductOverride::query()->where('product_id', 9520)->value('name'));
        $this->assertSame('888.00', (string) ProductVariantOverride::query()->where('product_variant_id', 9520)->value('unit_price'));
        $this->assertSame('Keep SEO', SeoMeta::query()->where('target_type', 'product')->where('target_key', '9520')->value('seo_title'));
    }

    public function test_literal_zero_body_is_reported_and_later_pages_still_sync(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $page = (int) ($query['page'] ?? 0);
            if ($page === 2) {
                return Http::response('0', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return Http::response($this->page([
                $this->item([
                    'id' => 90000 + $page,
                    'part_no' => 'MZ-P'.$page,
                    'slug' => 'mz-p'.$page,
                    'name' => 'OPEL PAGE '.$page,
                ]),
            ], $page, 3));
        });

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('body: 0')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-P1')->count());
        $this->assertSame(0, DB::table('product_variants')->where('part_no', 'MZ-P2')->count());
        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-P3')->count());
    }

    public function test_unexpected_payload_is_retried_then_accepted(): void
    {
        config(['catalog.b2b.retry' => 2]);
        $calls = 0;
        Http::preventStrayRequests();
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response('0', 200);
            }

            return Http::response($this->page([
                $this->item(['id' => 91000, 'part_no' => 'MZ-RETRY', 'slug' => 'mz-retry', 'name' => 'OPEL RETRY']),
            ]));
        });

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame(2, $calls);
        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-RETRY')->count());
    }

    public function test_upstream_error_json_on_the_first_page_fails_with_its_message(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'message' => 'Call to a member function first() on null',
            'exception' => 'Error',
        ], 200));

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('Call to a member function first() on null')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
    }

    public function test_three_bad_pages_in_a_row_stop_the_sync(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $page = (int) ($query['page'] ?? 0);
            if ($page === 1) {
                return Http::response($this->page([
                    $this->item(['id' => 92001, 'part_no' => 'MZ-KEEP', 'slug' => 'mz-keep', 'name' => 'OPEL KEEP']),
                ], 1, 6));
            }

            return Http::response('0', 200);
        });

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('3 pages in a row')
            ->assertFailed();

        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-KEEP')->count());
    }
}
