<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\Admin;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryPageTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private CategoryGroup $power;

    private CategoryGroup $cordless;

    private Category $grinder;

    private Brand $bosch;

    private Brand $unbranded;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Admin::factory()->super()->create(['email' => 'cat-admin@example.com']);
        $this->adminToken = $admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->power = CategoryGroup::factory()->create(['name' => 'POWER TOOLS', 'slug' => 'power-tools']);
        $this->cordless = CategoryGroup::factory()->create(['name' => 'CORDLESS TOOLS', 'slug' => 'cordless-tools']);
        $this->grinder = Category::factory()->create([
            'name' => 'ANGLE GRINDER',
            'slug' => 'angle-grinder',
            'category_group_id' => $this->power->id,
            'banner' => 'https://cdn.example.com/grinder.jpg',
        ]);
        $this->bosch = Brand::factory()->create(['name' => 'Bosch', 'slug' => 'bosch']);
        $this->unbranded = Brand::factory()->create(['name' => 'Unbranded', 'slug' => 'unbranded']);
    }

    private function product(string $partNo, Category $category, int $stock = 5, array $attrs = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => 'Item '.$partNo,
            'category_id' => $category->id,
            'group_id' => $category->category_group_id,
            'brand_id' => $this->bosch->id,
            'thumbnail_img' => 'https://cdn.example.com/'.$partNo.'.jpg',
        ], $attrs));
        $product->defaultVariant->update([
            'part_no' => $partNo,
            'unit_price' => 1000,
            'mrp' => 1000,
            'list_price' => 1000,
            'current_stock' => $stock,
        ]);

        return $product;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    public function test_group_page_returns_categories_capped_spotlights_and_only_sellable_products(): void
    {
        $sellable = $this->product('PT-1', $this->grinder);
        $this->product('PT-OUT', $this->grinder, 0);
        $this->product('PT-UNPUB', $this->grinder, 5, ['published' => false]);
        $this->product('PT-UNBRAND', $this->grinder, 5, ['brand_id' => $this->unbranded->id]);
        $hidden = $this->product('PT-HID', $this->grinder);
        $this->asAdmin()->putJson('/api/v1/admin/catalog/products/'.$hidden->id, [
            'expected_version' => 0,
            'fields' => ['hidden' => true],
        ])->assertOk();

        // Create 7 more visible categories with sellable stock; spotlights must cap at 6.
        for ($i = 1; $i <= 7; $i++) {
            $cat = Category::factory()->create([
                'name' => 'Cat '.$i,
                'slug' => 'cat-'.$i,
                'category_group_id' => $this->power->id,
            ]);
            $this->product('C-'.$i, $cat);
        }

        // Hidden category must be excluded.
        $hiddenCat = Category::factory()->create([
            'name' => 'Secret Cat',
            'slug' => 'secret-cat',
            'category_group_id' => $this->power->id,
        ]);
        $this->product('SEC-1', $hiddenCat);
        $this->asAdmin()->putJson('/api/v1/admin/catalog/categories/'.$hiddenCat->id, [
            'expected_version' => 0,
            'fields' => ['hidden' => true],
        ])->assertOk();

        $res = $this->getJson('/api/v1/category-pages/power-tools')->assertOk();
        $res->assertJsonPath('data.kind', 'group')
            ->assertJsonPath('data.title', 'POWER TOOLS')
            ->assertJsonPath('data.products_href', '/products?group=power-tools')
            ->assertJsonCount(8, 'data.children')
            ->assertJsonCount(6, 'data.child_spotlights');

        $productIds = collect($res->json('data.products'))->pluck('id')->all();
        $this->assertContains($sellable->id, $productIds);
        $this->assertNotContains($hidden->id, $productIds);

        $spotlightBrands = collect($res->json('data.child_spotlights'))->flatMap(fn ($s) => $s['brands'])->pluck('slug')->all();
        $this->assertNotContains('unbranded', $spotlightBrands);
        $this->assertContains('bosch', $spotlightBrands);
    }

    public function test_out_of_stock_products_are_listed_last_on_group_and_category_pages(): void
    {
        $out = $this->product('OOS-1', $this->grinder, 0);
        $this->product('IN-1', $this->grinder);
        $this->product('IN-2', $this->grinder);

        foreach (['/api/v1/category-pages/power-tools', '/api/v1/category-pages/power-tools/angle-grinder'] as $url) {
            $products = collect($this->getJson($url)->assertOk()->json('data.products'));
            $this->assertCount(3, $products, $url);
            $this->assertSame($out->id, $products->last()['id'], $url);
            $this->assertFalse($products->last()['in_stock'], $url);
            $this->assertTrue($products->first()['in_stock'], $url);
        }
    }

    public function test_category_page_validates_https_banner_and_includes_parent_breadcrumbs(): void
    {
        $parent = Category::factory()->create([
            'name' => 'Drills',
            'slug' => 'drills',
            'category_group_id' => $this->power->id,
        ]);
        $child = Category::factory()->create([
            'name' => 'Hammer Drills',
            'slug' => 'hammer-drills',
            'category_group_id' => $this->power->id,
            'parent_id' => $parent->id,
            'banner' => 'http://insecure.example.com/banner.jpg',
        ]);
        $sellable = $this->product('HD-1', $child);

        $res = $this->getJson('/api/v1/category-pages/power-tools/hammer-drills')->assertOk();
        $res->assertJsonPath('data.kind', 'category')
            ->assertJsonPath('data.title', 'Hammer Drills')
            ->assertJsonPath('data.products_href', '/products?group=power-tools&category=hammer-drills')
            ->assertJsonCount(0, 'data.hero_slides')
            ->assertJsonPath('data.breadcrumbs.1.href', '/category/power-tools')
            ->assertJsonPath('data.breadcrumbs.2.href', '/category/power-tools/drills')
            ->assertJsonPath('data.products.0.id', $sellable->id);

        // https:// banner is kept on valid category.
        $this->getJson('/api/v1/category-pages/power-tools/angle-grinder')->assertOk()
            ->assertJsonCount(1, 'data.hero_slides')
            ->assertJsonPath('data.hero_slides.0.image_url', 'https://cdn.example.com/grinder.jpg');
    }

    public function test_category_slug_without_group_returns_canonical_redirect(): void
    {
        $this->getJson('/api/v1/category-pages/angle-grinder')->assertOk()
            ->assertJsonPath('data.redirect_to', '/category/power-tools/angle-grinder');
    }

    public function test_hidden_mismatched_and_unknown_slugs_return_404(): void
    {
        $this->getJson('/api/v1/category-pages/does-not-exist')->assertNotFound();
        $this->getJson('/api/v1/category-pages/cordless-tools/angle-grinder')->assertNotFound();

        $this->asAdmin()->putJson('/api/v1/admin/catalog/groups/'.$this->power->id, [
            'expected_version' => 0,
            'fields' => ['hidden' => true],
        ])->assertOk();

        $this->getJson('/api/v1/category-pages/power-tools')->assertNotFound();
        $this->getJson('/api/v1/category-pages/power-tools/angle-grinder')->assertNotFound();
        $this->getJson('/api/v1/category-pages/angle-grinder')->assertNotFound();
    }

    public function test_catalog_cache_bump_and_price_override_refresh_category_page(): void
    {
        $p = $this->product('PT-1', $this->grinder);
        $this->getJson('/api/v1/category-pages/power-tools/angle-grinder')
            ->assertOk()
            ->assertJsonPath('data.products.0.unit_price', '1100.00');

        $before = CatalogCache::version();
        $this->asAdmin()->putJson('/api/v1/admin/catalog/variants/'.$p->defaultVariant->id, [
            'expected_version' => 0,
            'fields' => ['unit_price' => 850],
        ])->assertOk();
        $this->assertGreaterThan($before, CatalogCache::version());

        $this->getJson('/api/v1/category-pages/power-tools/angle-grinder')
            ->assertOk()
            ->assertJsonPath('data.products.0.unit_price', '850.00');
    }
}
