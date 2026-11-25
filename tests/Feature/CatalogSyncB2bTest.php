<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Overrides\ProductOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\B2bCatalogSync;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Support\OrderTimeline;
use App\Modules\Seo\Models\SeoMeta;
use App\Modules\Seo\Support\SeoCache;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CatalogSyncB2bTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://b2b.test/api/b2c/products/all';

    /** @var array<string, mixed>|int Next response body, or an HTTP status to fail with. */
    private array|int $feed = 500;

    /** @var list<array<string, mixed>|string> Responses served first, in order. */
    private array $queue = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'b2b_api.base_url' => 'https://b2b.test/api/b2c',
            'b2b_api.token' => 'test-token',
            'catalog.b2b.retry' => 1,
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame(self::URL, $request->url(), 'one GET to /products/all, nothing in the query');
            $this->assertSame('Bearer test-token', $request->header('Authorization')[0] ?? null);

            if ($this->queue !== []) {
                return Http::response(array_shift($this->queue));
            }

            return is_int($this->feed)
                ? Http::response(['res' => false], $this->feed)
                : Http::response($this->feed);
        });
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
            'mrp' => '1055',
            'is_warranty' => 0,
            'warranty_duration' => '0',
            'thumb_img' => ['id' => 1, 'file_name' => 'https://mazingbusiness.com/public/uploads/all/thumb_/x.jpg'],
            'images' => [['id' => 2, 'file_name' => 'https://mazingbusiness.com/public/uploads/all/x.jpg']],
            'stocks' => [
                ['warehouse_id' => 2, 'warehouse_name' => 'Delhi', 'qty' => 12],
                ['warehouse_id' => 6, 'warehouse_name' => 'Mumbai', 'qty' => 348],
                ['warehouse_id' => 1, 'warehouse_name' => 'Kolkata', 'qty' => 12],
            ],
            'category_group' => ['id' => 1, 'name' => 'POWER TOOLS'],
            'category' => ['id' => 1, 'name' => 'AIR BLOWER', 'image' => null],
            'reviews' => [['id' => 1, 'product_id' => 9520, 'rating' => 1, 'comment' => 'test']],
        ];
    }

    /** @return array<string, mixed> */
    private function sku(int $id, string $partNo, array $overrides = []): array
    {
        return $this->item($overrides + [
            'id' => $id, 'part_no' => $partNo, 'name' => 'OPEL ITEM '.$partNo, 'slug' => 'opel-item-'.strtolower($partNo),
            'stocks' => [['warehouse_id' => 2, 'warehouse_name' => 'Delhi', 'qty' => 1]],
        ]);
    }

    /**
     * @param  list<array<string, mixed>|mixed>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items, ?int $total = null): array
    {
        return [
            'data' => [
                'current_page' => 1,
                'data' => $items,
                'per_page' => count($items),
                'total' => $total ?? count($items),
                'last_page' => 1,
                'next_page_url' => null,
            ],
            'res' => true,
            'msg' => '',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function skus(int $count, int $from = 1): array
    {
        $items = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $items[] = $this->sku(60000 + $i, 'MZ-T'.$i);
        }

        return $items;
    }

    private function published(string $partNo): bool
    {
        $productId = DB::table('product_variants')->where('part_no', $partNo)->value('product_id');

        return (bool) DB::table('products')->where('id', $productId)->value('published');
    }

    public function test_existing_seeded_part_no_keeps_local_id_slug_description_and_overrides(): void
    {
        $this->seed(CatalogSeeder::class);
        $originalSlug = DB::table('products')->where('id', 1)->value('slug');
        DB::table('products')->where('id', 1)->update(['description' => 'Keep me']);
        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Admin name']);
        ProductVariantOverride::query()->create(['product_variant_id' => 1, 'unit_price' => '999.00']);

        $this->feed = $this->payload([$this->item(['name' => 'NEW BLOWER NAME', 'slug' => 'would-overwrite-slug', 'mrp' => '2000'])]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $product = DB::table('products')->where('id', 1)->first();
        $variant = DB::table('product_variants')->where('id', 1)->first();
        $this->assertSame('MZ11018', $variant->part_no);
        $this->assertSame($originalSlug, $product->slug);
        $this->assertSame('NEW BLOWER NAME', $product->name);
        $this->assertSame('Keep me', $product->description);
        $this->assertSame(1, (int) $product->published);
        $this->assertEqualsWithDelta(2000.0, (float) $variant->mrp, 0.001);
        $this->assertNull(Product::query()->find(9520));
        $this->assertSame('Admin name', Product::query()->find(1)->name);
        $this->assertSame('999.00', (string) ProductVariant::query()->find(1)->unit_price);
    }

    public function test_new_part_no_inserts_with_b2b_id_and_marked_up_price(): void
    {
        $this->feed = $this->payload([$this->sku(56001, 'MZ99999', [
            'name' => 'OPEL NEW STEEL BAR', 'slug' => 'opel-new-steel-bar', 'mrp' => '1115.13',
            'category_group' => ['id' => 8, 'name' => 'HARDWARE'],
            'category' => ['id' => 80, 'name' => 'STEEL BAR', 'image' => null],
        ])]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame(1, (int) DB::table('products')->where('id', 56001)->value('published'));
        $this->assertSame('opel-new-steel-bar', DB::table('products')->where('id', 56001)->value('slug'));
        $this->assertSame(56001, (int) DB::table('product_variants')->where('part_no', 'MZ99999')->value('id'));
        $this->assertEqualsWithDelta(1115.13, (float) DB::table('product_variants')->where('part_no', 'MZ99999')->value('mrp'), 0.001);
        $this->assertEqualsWithDelta(1226.64, (float) ProductVariant::query()->where('part_no', 'MZ99999')->first()->unit_price, 0.001);
    }

    public function test_new_part_no_does_not_steal_an_existing_id(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->feed = $this->payload([$this->sku(1, 'MZ-NEW-1')]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame('MZ11018', DB::table('product_variants')->where('id', 1)->value('part_no'));
        $created = DB::table('product_variants')->where('part_no', 'MZ-NEW-1')->first();
        $this->assertGreaterThan(45, (int) $created->product_id);
    }

    public function test_stock_is_matched_by_warehouse_id_and_unmapped_or_omitted_warehouses_are_zero(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->feed = $this->payload([$this->item(['stocks' => [
            ['warehouse_id' => 2, 'warehouse_name' => 'Somewhere', 'qty' => 10],
            ['warehouse_id' => 3, 'warehouse_name' => 'Chennai', 'qty' => 99],
        ]])]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $rows = DB::table('product_warehouses')->where('product_variant_id', 1)->pluck('qty', 'warehouse_code')->all();
        $this->assertSame(['DELHI', 'KOLKATA', 'MUMBAI'], collect($rows)->keys()->sort()->values()->all());
        $this->assertSame(10, (int) $rows['DELHI']);
        $this->assertSame(0, (int) $rows['MUMBAI']);
        $this->assertSame(0, (int) $rows['KOLKATA']);
        $this->assertSame(10, (int) DB::table('product_variants')->where('id', 1)->value('current_stock'));
    }

    public function test_zero_mrp_is_stored_but_unpublished_and_refused_everywhere(): void
    {
        $this->feed = $this->payload([
            $this->sku(70001, 'MZ-ZERO', ['mrp' => '0']),
            $this->sku(70002, 'MZ-OK', ['mrp' => '10']),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-ZERO')->count());
        $this->assertFalse($this->published('MZ-ZERO'));
        $this->assertTrue($this->published('MZ-OK'));
        $this->getJson('/api/v1/products/70001')->assertNotFound();

        $this->feed = $this->payload([
            $this->sku(70001, 'MZ-ZERO', ['mrp' => '50']),
            $this->sku(70002, 'MZ-OK', ['mrp' => '10']),
        ]);
        $this->artisan('catalog:sync-b2b')->assertSuccessful();
        $this->assertTrue($this->published('MZ-ZERO'));
    }

    public function test_warranty_is_stored_and_exposed_only_when_present(): void
    {
        $this->feed = $this->payload([
            $this->sku(70010, 'MZ-W', ['is_warranty' => 1, 'warranty_duration' => '6']),
            $this->sku(70011, 'MZ-NW', ['is_warranty' => 0, 'warranty_duration' => '12']),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->getJson('/api/v1/products/70010')->assertOk()->assertJsonPath('data.warranty_months', 6);
        $this->getJson('/api/v1/products/70011')->assertOk()->assertJsonPath('data.warranty_months', null);

        $this->feed = $this->payload([
            $this->sku(70010, 'MZ-W', ['is_warranty' => 0]),
            $this->sku(70011, 'MZ-NW', ['is_warranty' => 1, 'warranty_duration' => '12']),
        ]);
        $this->artisan('catalog:sync-b2b')->assertSuccessful();
        $this->assertNull(DB::table('products')->where('id', 70010)->value('warranty_months'));
        $this->assertSame(12, (int) DB::table('products')->where('id', 70011)->value('warranty_months'));
    }

    public function test_first_complete_run_only_records_a_baseline(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->feed = $this->payload([$this->item()]);

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('no earlier complete run')
            ->assertSuccessful();

        $this->assertSame(45, Product::query()->where('published', true)->count());
        $run = DB::table('catalog_sync_runs')->first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, (int) $run->complete);
        $this->assertSame(1, (int) $run->received);
    }

    public function test_products_missing_from_a_complete_list_are_unpublished_not_deleted(): void
    {
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();
        ProductOverride::query()->create(['product_id' => 60001, 'name' => 'Keep override']);

        Log::spy();
        $this->feed = $this->payload($this->skus(19, 2));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertFalse($this->published('MZ-T1'));
        $this->assertTrue($this->published('MZ-T2'));
        $this->assertSame(20, Product::query()->count());
        $this->assertSame('Keep override', ProductOverride::query()->where('product_id', 60001)->value('name'));
        $this->assertSame(1, (int) DB::table('catalog_sync_runs')->orderByDesc('id')->value('unpublished'));
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'unpublished products missing') && $ctx['part_nos'] === ['MZ-T1'])->once();

        // It comes back on the next list.
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();
        $this->assertTrue($this->published('MZ-T1'));
    }

    public function test_listed_row_that_fails_stays_published_and_failures_block_unpublishing(): void
    {
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $real = app(B2bCatalogSync::class);
        $mock = \Mockery::mock(B2bCatalogSync::class)->makePartial();
        $mock->shouldReceive('upsertItem')->andReturnUsing(function (array $item, $now, int $taxId) use ($real): string {
            if ($item['part_no'] === 'MZ-T3') {
                throw new RuntimeException('db hiccup');
            }

            return $real->upsertItem($item, $now, $taxId);
        });
        $this->app->instance(B2bCatalogSync::class, $mock);

        // MZ-T1 is gone, MZ-T3 is listed but fails to write.
        $this->feed = $this->payload($this->skus(19, 2));
        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('Not unpublishing: 1 rows failed to sync.')
            ->assertSuccessful();

        $this->assertTrue($this->published('MZ-T3'));
        $this->assertTrue($this->published('MZ-T1'));
        $this->assertSame(0, (int) DB::table('catalog_sync_runs')->orderByDesc('id')->value('unpublished'));
    }

    public function test_listed_row_that_is_skipped_is_not_unpublished(): void
    {
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        // MZ-T1 is gone; MZ-T3 comes back without a category and is skipped.
        $rows = $this->skus(19, 2);
        foreach ($rows as $i => $row) {
            if ($row['part_no'] === 'MZ-T3') {
                $rows[$i]['category'] = null;
            }
        }
        $this->feed = $this->payload($rows);
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertFalse($this->published('MZ-T1'));
        $this->assertTrue($this->published('MZ-T3'));
        $run = DB::table('catalog_sync_runs')->orderByDesc('id')->first();
        $this->assertSame([1, 1], [(int) $run->skipped, (int) $run->unpublished]);
    }

    public function test_incomplete_response_unpublishes_nothing(): void
    {
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->feed = $this->payload($this->skus(19, 2), 20);
        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('total does not match')
            ->assertSuccessful();

        $this->assertTrue($this->published('MZ-T1'));
    }

    public function test_count_far_from_last_good_run_unpublishes_nothing(): void
    {
        $this->feed = $this->payload($this->skus(20));
        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->feed = $this->payload($this->skus(18, 3));
        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('more than 5% apart')
            ->assertSuccessful();

        $this->assertTrue($this->published('MZ-T1'));
        $this->assertTrue($this->published('MZ-T2'));
    }

    public function test_failed_fetch_changes_nothing_and_does_not_bump_cache(): void
    {
        $this->seed(CatalogSeeder::class);
        $before = DB::table('products')->orderBy('id')->pluck('published', 'id')->all();
        $cache = CatalogCache::version();
        $this->feed = 500;

        $this->artisan('catalog:sync-b2b')->assertFailed();

        $this->assertSame($before, DB::table('products')->orderBy('id')->pluck('published', 'id')->all());
        $this->assertSame($cache, CatalogCache::version());
        $this->assertSame('failed', DB::table('catalog_sync_runs')->value('status'));
    }

    public function test_invalid_token_response_fails_with_its_message_and_never_leaks_the_token(): void
    {
        $this->feed = ['success' => false, 'message' => 'Invalid B2C API Token.'];

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('Invalid B2C API Token.')
            ->doesntExpectOutputToContain('test-token')
            ->assertFailed();

        $this->assertStringNotContainsString('test-token', (string) DB::table('catalog_sync_runs')->value('message'));
    }

    public function test_missing_token_fails_without_calling_b2b(): void
    {
        config(['b2b_api.token' => null]);

        $this->artisan('catalog:sync-b2b')->expectsOutputToContain('B2B_STOCK_TOKEN is not set')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_unexpected_payload_is_retried_then_accepted(): void
    {
        config(['catalog.b2b.retry' => 2]);
        $this->queue = ['0', $this->payload([$this->sku(80000, 'MZ-ONLY')])];

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-ONLY')->count());
    }

    public function test_one_call_syncs_everything_and_bumps_catalog_and_seo_cache(): void
    {
        $catalog = CatalogCache::version();
        $seo = SeoCache::version();
        $this->feed = $this->payload($this->skus(3));

        $this->artisan('catalog:sync-b2b')->expectsOutputToContain('3 inserted')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame($catalog + 1, CatalogCache::version());
        $this->assertSame($seo + 1, SeoCache::version());
    }

    public function test_rows_are_written_in_chunks_and_a_bad_or_failing_row_does_not_stop_the_rest(): void
    {
        config(['catalog.b2b.chunk' => 2]);
        $real = app(B2bCatalogSync::class);
        $mock = \Mockery::mock(B2bCatalogSync::class)->makePartial();
        $mock->shouldReceive('upsertItem')->andReturnUsing(function (array $item, $now, int $taxId) use ($real): string {
            if ($item['part_no'] === 'MZ-T2') {
                throw new RuntimeException('boom');
            }

            return $real->upsertItem($item, $now, $taxId);
        });
        $this->app->instance(B2bCatalogSync::class, $mock);

        $this->feed = $this->payload([...$this->skus(4), 'garbage', $this->sku(90, '')]);

        $this->artisan('catalog:sync-b2b')
            ->expectsOutputToContain('Row MZ-T2 failed: boom')
            ->expectsOutputToContain('Skipped a row (no part_no)')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('product_variants')->where('part_no', 'like', 'MZ-T%')->count());
        $run = DB::table('catalog_sync_runs')->first();
        $this->assertSame([3, 1, 2], [(int) $run->inserted, (int) $run->failed, (int) $run->skipped]);
    }

    public function test_duplicate_part_no_logs_both_b2b_ids_and_later_row_wins(): void
    {
        Log::spy();
        $this->feed = $this->payload([
            $this->sku(70100, 'MZ-DUP', ['mrp' => '100']),
            $this->sku(70101, 'MZ-DUP', ['mrp' => '200']),
        ]);

        $this->artisan('catalog:sync-b2b')->assertSuccessful();

        $this->assertSame(1, DB::table('product_variants')->where('part_no', 'MZ-DUP')->count());
        $this->assertEqualsWithDelta(200.0, (float) DB::table('product_variants')->where('part_no', 'MZ-DUP')->value('mrp'), 0.001);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'duplicate part_no')
            && $ctx['first_b2b_id'] === 70100 && $ctx['later_b2b_id'] === 70101)->once();
    }

    public function test_wipe_demo_seed_lets_sync_insert_live_b2b_ids(): void
    {
        $this->seed(CatalogSeeder::class);
        ProductOverride::query()->create(['product_id' => 1, 'name' => 'Orphan me']);
        ProductVariantOverride::query()->create(['product_variant_id' => 1, 'unit_price' => '999.00']);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => '1', 'seo_title' => 'Old', 'version' => 1]);

        $this->feed = $this->payload([$this->item()]);
        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();

        $variant = DB::table('product_variants')->where('part_no', 'MZ11018')->first();
        $this->assertSame(9520, (int) $variant->id);
        $this->assertSame(9520, (int) $variant->product_id);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, ProductOverride::query()->count());
        $this->assertSame(0, ProductVariantOverride::query()->count());
        $this->assertSame(0, SeoMeta::query()->where('target_type', 'product')->count());
        $this->assertSame(1, DB::table('category_groups')->count());
    }

    public function test_wipe_demo_seed_leaves_orders_and_their_products_in_place(): void
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
            'order_id' => $order->id, 'product_id' => 1, 'variant_id' => 1, 'title' => 'Seeded blower',
            'unit_price' => 1, 'qty' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->feed = $this->payload([$this->item()]);
        $this->artisan('catalog:sync-b2b', ['--wipe-demo-seed' => true])->assertSuccessful();

        $this->assertSame(9520, (int) DB::table('product_variants')->where('part_no', 'MZ11018')->value('id'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('order_items')->where('product_id', 1)->count());
    }
}
