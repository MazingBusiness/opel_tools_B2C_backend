<?php

namespace Tests\Feature;

use App\Modules\Address\Models\Address;
use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Overrides\CategoryOverride;
use App\Modules\Catalog\Models\Overrides\ProductOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Seo\Support\SeoCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CatalogOverridesTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private CategoryGroup $group;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->staff()->create(['email' => 'catalog-admin@example.com']);
        $this->adminToken = $admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->group = CategoryGroup::factory()->create(['name' => 'POWER TOOLS', 'slug' => 'power-tools']);
        $this->category = Category::factory()->create([
            'name' => 'AIR BLOWER', 'slug' => 'air-blower', 'category_group_id' => $this->group->id,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'B2B Blower',
            'description' => 'B2B description',
            'group_id' => $this->group->id,
            'category_id' => $this->category->id,
        ]);
        $this->product->defaultVariant->update(['unit_price' => 1000, 'mrp' => 1200, 'list_price' => 1200, 'current_stock' => 10]);
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function save(string $entity, int $id, array $fields, ?int $version = null): TestResponse
    {
        if ($version === null) {
            $version = (int) $this->asAdmin()->getJson("/api/v1/admin/catalog/$entity/$id")->json('version');
        }

        return $this->asAdmin()->putJson("/api/v1/admin/catalog/$entity/$id", [
            'expected_version' => $version,
            'fields' => $fields,
        ]);
    }

    private function variantId(): int
    {
        return (int) $this->product->defaultVariant->id;
    }

    private function field(TestResponse $res, string $name, ?string $path = 'fields'): array
    {
        return collect($res->json($path))->firstWhere('field', $name);
    }

    // ---- merge -----------------------------------------------------------

    public function test_merge_returns_override_else_b2b_and_keeps_b2b_rows_untouched(): void
    {
        $this->getJson('/api/v1/products/'.$this->product->id)->assertOk()->assertJsonPath('data.name', 'B2B Blower');

        $res = $this->save('products', $this->product->id, ['name' => 'Admin Blower'])->assertOk();
        $row = $this->field($res, 'name');
        $this->assertSame('B2B Blower', $row['b2b']);
        $this->assertSame('Admin Blower', $row['override']);
        $this->assertSame('Admin Blower', $row['effective']);
        $this->assertTrue($row['overridden']);
        $this->assertSame(['name'], $res->json('overridden_fields'));
        $this->assertSame(1, $res->json('version'));

        // Effective for storefront; description (no override) falls back to B2B.
        $this->getJson('/api/v1/products/'.$this->product->id)->assertOk()
            ->assertJsonPath('data.name', 'Admin Blower')
            ->assertJsonPath('data.description', 'B2B description');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.name', 'Admin Blower');

        // B2B base row never written.
        $this->assertSame('B2B Blower', DB::table('products')->where('id', $this->product->id)->value('name'));
    }

    public function test_null_resets_field_explicit_empty_is_an_override_and_reset_endpoint(): void
    {
        $this->save('products', $this->product->id, ['name' => 'X', 'description' => ''])->assertOk();
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.description', '')->assertJsonPath('data.name', 'X');

        // null = reset just that field.
        $res = $this->save('products', $this->product->id, ['description' => null])->assertOk();
        $this->assertSame(['name'], $res->json('overridden_fields'));
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.description', 'B2B description');

        // Reset endpoint: per field.
        $res = $this->asAdmin()->postJson('/api/v1/admin/catalog/products/'.$this->product->id.'/reset', [
            'expected_version' => $res->json('version'),
            'fields' => ['name'],
        ])->assertOk();
        $this->assertSame([], $res->json('overridden_fields'));
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.name', 'B2B Blower');
    }

    public function test_storefront_category_apis_serve_effective_names_and_sort_order(): void
    {
        $second = Category::factory()->create(['name' => 'ZZZ LAST', 'slug' => 'zzz', 'category_group_id' => $this->group->id]);

        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.slug', 'air-blower');

        $this->save('categories', $second->id, ['name' => 'Renamed', 'sort_order' => 1])->assertOk();

        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('data.0.slug', 'zzz')
            ->assertJsonPath('data.0.name', 'Renamed');
        $this->getJson('/api/v1/category-groups')->assertOk()
            ->assertJsonPath('data.0.categories.0.name', 'Renamed');

        $this->save('groups', $this->group->id, ['name' => 'Tools!'])->assertOk();
        $this->getJson('/api/v1/category-groups')->assertJsonPath('data.0.name', 'Tools!');
    }

    public function test_variant_price_override_flows_to_list_detail_and_price_filter(): void
    {
        $this->save('variants', $this->variantId(), ['unit_price' => 750])->assertOk();

        $this->getJson('/api/v1/products')->assertJsonPath('data.0.unit_price', '750.00');
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.variants.0.unit_price', '750.00');
        $this->getJson('/api/v1/products?min=700&max=800')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?min=900')->assertJsonCount(0, 'data');
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('product_variants')->where('id', $this->variantId())->value('unit_price'), 0.001);
    }

    public function test_price_overrides_must_be_positive(): void
    {
        foreach (['unit_price', 'mrp', 'list_price', 'carton_price'] as $field) {
            foreach ([0, -5] as $bad) {
                $this->save('variants', $this->variantId(), [$field => $bad])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(["fields.$field"]);
            }
        }
        $this->assertSame(0, ProductVariantOverride::query()->count());

        $this->save('variants', $this->variantId(), ['carton_price' => 0.5])->assertOk();
        $this->save('variants', $this->variantId(), ['carton_price' => null])->assertOk(); // null = reset, still allowed
    }

    public function test_save_rejects_unit_price_above_effective_mrp_or_list_price(): void
    {
        // B2B: unit 1000, mrp 1200, list 1200.
        $this->save('variants', $this->variantId(), ['unit_price' => 1300])
            ->assertUnprocessable()->assertJsonValidationErrors(['fields.unit_price']);
        $this->save('variants', $this->variantId(), ['mrp' => 900])
            ->assertUnprocessable()->assertJsonValidationErrors(['fields.unit_price']);
        $this->save('variants', $this->variantId(), ['list_price' => 999.99])
            ->assertUnprocessable()->assertJsonValidationErrors(['fields.unit_price']);
        // Both sides in one patch are checked together.
        $this->save('variants', $this->variantId(), ['unit_price' => 1500, 'mrp' => 1600, 'list_price' => 1600])->assertOk();
        // Equal is fine.
        $this->save('variants', $this->variantId(), ['unit_price' => 1600])->assertOk();

        // Null side is skipped (B2B has no list price).
        DB::table('product_variants')->where('id', $this->variantId())->update(['list_price' => null]);
        $this->save('variants', $this->variantId(), ['list_price' => null])->assertOk();
        $this->save('variants', $this->variantId(), ['unit_price' => 1600])->assertOk();

        // Non-price edits are never blocked by price data.
        $this->save('variants', $this->variantId(), ['label' => 'Blue'])->assertOk();
    }

    public function test_reset_rejects_price_inversion(): void
    {
        // Raise both, then try to reset only mrp → B2B mrp 1200 < unit 1500.
        $res = $this->save('variants', $this->variantId(), ['unit_price' => 1500, 'mrp' => 1600, 'list_price' => 1600])->assertOk();
        $this->asAdmin()->postJson('/api/v1/admin/catalog/variants/'.$this->variantId().'/reset', [
            'expected_version' => $res->json('version'),
            'fields' => ['mrp'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['fields.unit_price']);
        $this->assertSame('1600.00', (string) ProductVariantOverride::query()->value('mrp'));

        // Resetting all prices together is consistent with B2B again → allowed.
        $this->asAdmin()->postJson('/api/v1/admin/catalog/variants/'.$this->variantId().'/reset', [
            'expected_version' => $res->json('version'),
        ])->assertOk()->assertJsonPath('overridden_fields', []);
    }

    // ---- locked fields ----------------------------------------------------

    public function test_part_no_ids_and_slugs_are_locked(): void
    {
        foreach (['part_no' => 'HACK', 'id' => 99, 'product_id' => 5] as $field => $value) {
            $this->save('variants', $this->variantId(), [$field => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["fields.$field"]);
        }
        foreach (['slug' => 'new-slug', 'category_id' => 1, 'published' => true] as $field => $value) {
            $this->save('products', $this->product->id, [$field => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["fields.$field"]);
        }
        $this->save('products', $this->product->id, ['made_up' => 'x'])->assertUnprocessable();
        $this->assertSame(0, ProductVariantOverride::query()->count() + ProductOverride::query()->count());
    }

    public function test_order_lines_keep_raw_part_no_and_ids(): void
    {
        $this->save('variants', $this->variantId(), ['label' => 'Shown label'])->assertOk();
        $order = $this->placeCodOrder();

        $line = OrderItem::query()->where('order_id', $order['db_id'])->firstOrFail();
        $this->assertSame($this->product->id, (int) $line->product_id);
        $this->assertSame($this->variantId(), (int) $line->variant_id);
        $this->assertSame($this->product->defaultVariant->part_no, DB::table('product_variants')->where('id', $line->variant_id)->value('part_no'));
    }

    // ---- stock ----------------------------------------------------------------

    public function test_stock_override_can_only_lower(): void
    {
        $this->save('variants', $this->variantId(), ['current_stock' => 11])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields.current_stock']);

        $this->save('variants', $this->variantId(), ['current_stock' => 3])->assertOk();
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.variants.0.current_stock', 3);

        // B2B stock later drops below the cap: effective follows B2B (never raised).
        DB::table('product_variants')->where('id', $this->variantId())->update(['current_stock' => 2]);
        $this->getJson('/api/v1/products/'.$this->product->id)->assertJsonPath('data.variants.0.current_stock', 2);

        // Force out of stock.
        DB::table('product_variants')->where('id', $this->variantId())->update(['current_stock' => 10]);
        $this->save('variants', $this->variantId(), ['current_stock' => 0])->assertOk();
        $this->getJson('/api/v1/products/'.$this->product->id)
            ->assertJsonPath('data.in_stock', false)
            ->assertJsonPath('data.variants.0.current_stock', 0);
        $this->getJson('/api/v1/products?in_stock=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.in_stock', false);

        $this->shopper();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'qty' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['qty']);
    }

    // ---- visibility -------------------------------------------------------

    public function test_hidden_product_category_and_group_are_off_the_storefront(): void
    {
        $this->save('products', $this->product->id, ['hidden' => true])->assertOk();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/'.$this->product->id)->assertNotFound();
        $this->asAdmin()->getJson('/api/v1/admin/catalog/products?filter=hidden')->assertJsonCount(1, 'data');
        $this->save('products', $this->product->id, ['hidden' => null])->assertOk();
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');

        // Hidden parent category hides its subcategories and their products.
        $sub = Category::factory()->create([
            'name' => 'SUB', 'slug' => 'sub', 'parent_id' => $this->category->id, 'category_group_id' => $this->group->id,
        ]);
        DB::table('products')->where('id', $this->product->id)->update(['category_id' => $sub->id]);
        $this->save('categories', $this->category->id, ['hidden' => true])->assertOk();
        $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seo?path='.urlencode('/products/'.$this->product->id))->assertJsonPath('robots', fn ($r) => str_contains((string) $r, 'noindex'));

        CategoryOverride::query()->delete();
        $this->save('groups', $this->group->id, ['hidden' => true])->assertOk();
        $this->getJson('/api/v1/category-groups')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_hidden_product_in_cart_cannot_be_ordered(): void
    {
        $this->shopper();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'qty' => 1])->assertCreated();
        $this->save('products', $this->product->id, ['hidden' => true])->assertOk();

        $this->shopper();
        $this->getJson('/api/v1/cart')->assertJsonPath('data.0.available', false);
        $this->postJson('/api/v1/orders', ['address_id' => $this->address()->id, 'payment_method' => 'cod'])
            ->assertUnprocessable();
    }

    // ---- versioning + cache -------------------------------------------------

    public function test_stale_version_is_rejected_with_409(): void
    {
        $this->save('products', $this->product->id, ['name' => 'First'], 0)->assertOk()->assertJsonPath('version', 1);

        // Second tab still on version 0 → conflict, nothing written.
        $this->save('products', $this->product->id, ['name' => 'Stale'], 0)
            ->assertStatus(409)
            ->assertJsonPath('current.version', 1);
        $this->save('products', $this->product->id, ['name' => 'Stale'], 7)->assertStatus(409);
        $this->asAdmin()->postJson('/api/v1/admin/catalog/products/'.$this->product->id.'/reset', ['expected_version' => 0])
            ->assertStatus(409);

        $this->assertSame('First', ProductOverride::query()->value('name'));
        $this->save('products', $this->product->id, ['name' => 'Second'], 1)->assertOk()->assertJsonPath('version', 2);
    }

    public function test_save_and_reset_clear_catalog_and_seo_caches(): void
    {
        $catalog = CatalogCache::version();
        $seo = SeoCache::version();

        // Warm the SEO payload cache with the B2B name.
        $this->getJson('/api/v1/seo?path='.urlencode('/products/'.$this->product->id))->assertOk();

        $res = $this->save('products', $this->product->id, ['name' => 'Cache Buster'])->assertOk();
        $this->assertGreaterThan($catalog, CatalogCache::version());
        $this->assertGreaterThan($seo, SeoCache::version());
        $this->assertStringContainsString('Cache Buster', (string) $this->getJson('/api/v1/seo?path='.urlencode('/products/'.$this->product->id))->getContent());

        $afterSave = CatalogCache::version();
        $this->asAdmin()->postJson('/api/v1/admin/catalog/products/'.$this->product->id.'/reset', [
            'expected_version' => $res->json('version'),
        ])->assertOk();
        $this->assertGreaterThan($afterSave, CatalogCache::version());

        // A 409 does not bump.
        $v = CatalogCache::version();
        $this->save('products', $this->product->id, ['name' => 'x'], 99)->assertStatus(409);
        $this->assertSame($v, CatalogCache::version());
    }

    // ---- cart + checkout ----------------------------------------------------

    public function test_cart_and_checkout_use_effective_price_and_stock(): void
    {
        $this->save('variants', $this->variantId(), ['unit_price' => 800, 'current_stock' => 2])->assertOk();

        $this->shopper();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'qty' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['qty']);
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'qty' => 2])->assertCreated();

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.0.variant.unit_price', '800.00')
            ->assertJsonPath('data.0.line_total', 1600)
            ->assertJsonPath('meta.subtotal', 1600);

        // Price changes between cart and placement: order recomputes from the effective price.
        $version = (int) $this->asAdmin()->getJson('/api/v1/admin/catalog/variants/'.$this->variantId())->json('version');
        $this->save('variants', $this->variantId(), ['unit_price' => 700], $version)->assertOk();

        $this->shopper();
        $order = $this->postJson('/api/v1/orders', ['address_id' => $this->address()->id, 'payment_method' => 'cod'])
            ->assertCreated()->json('data');
        $line = OrderItem::query()->where('order_id', $order['db_id'])->firstOrFail();
        $this->assertSame('700.00', (string) $line->unit_price);
        $this->assertSame(2, (int) $line->qty);
        $this->assertEqualsWithDelta(1400.0, (float) DB::table('orders')->where('id', $order['db_id'])->value('subtotal'), 0.001);
    }

    // ---- admin APIs ---------------------------------------------------------

    public function test_admin_list_filters_detail_and_auth(): void
    {
        $this->getJson('/api/v1/admin/catalog/products')->assertUnauthorized();
        $shopper = User::factory()->create();
        $this->withToken($shopper->createToken('opel-b2c')->plainTextToken)
            ->getJson('/api/v1/admin/catalog/products')->assertForbidden();

        $other = Product::factory()->create(['name' => 'Other', 'group_id' => $this->group->id, 'category_id' => $this->category->id]);
        $this->save('variants', (int) $other->defaultVariant->id, ['label' => 'Shown label'])->assertOk();

        $this->asAdmin()->getJson('/api/v1/admin/catalog/products')->assertOk()->assertJsonPath('meta.total', 2);
        $this->asAdmin()->getJson('/api/v1/admin/catalog/products?filter=overridden')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.0.variants_overridden', true);
        $this->asAdmin()->getJson('/api/v1/admin/catalog/products?q=Other')->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson('/api/v1/admin/catalog/categories?level=top')->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson('/api/v1/admin/catalog/groups')->assertJsonPath('data.0.categories_count', 1);

        $detail = $this->asAdmin()->getJson('/api/v1/admin/catalog/products/'.$other->id)->assertOk()
            ->assertJsonPath('seo.admin_path', '/seo/product/'.$other->id)
            ->assertJsonPath('variants.0.overridden_fields', ['label']);
        $this->assertContains('part_no', array_column($detail->json('variants.0.locked'), 'field'));
        $this->asAdmin()->getJson('/api/v1/admin/catalog/products/999999')->assertNotFound();
    }

    // ---- helpers ------------------------------------------------------------

    private function shopper(): User
    {
        $this->app['auth']->forgetGuards();
        $user = User::query()->where('email', 'shopper@example.com')->first()
            ?? User::factory()->create(['email' => 'shopper@example.com']);
        $this->withToken($user->createToken('opel-b2c')->plainTextToken);

        return $user;
    }

    private function address(): Address
    {
        $user = User::query()->where('email', 'shopper@example.com')->firstOrFail();

        return Address::query()->firstOrCreate(['user_id' => $user->id], [
            'name' => 'Shopper', 'phone' => '9876543210', 'line1' => '1 Main Road', 'city' => 'Delhi',
            'state' => 'Delhi', 'pincode' => '110001', 'type' => 'home',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function placeCodOrder(): array
    {
        $this->shopper();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'qty' => 1])->assertCreated();

        return $this->postJson('/api/v1/orders', ['address_id' => $this->address()->id, 'payment_method' => 'cod'])
            ->assertCreated()->json('data');
    }
}
