<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private CategoryGroup $power;

    private CategoryGroup $cordless;

    private Category $grinder;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->staff()->create(['email' => 'home-admin@example.com']);
        $this->adminToken = $admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        $this->power = CategoryGroup::factory()->create(['name' => 'POWER TOOLS', 'slug' => 'power-tools']);
        $this->cordless = CategoryGroup::factory()->create(['name' => 'CORDLESS TOOLS', 'slug' => 'cordless-tools']);
        $this->grinder = Category::factory()->create(['name' => 'ANGLE GRINDER', 'slug' => 'angle-grinder', 'category_group_id' => $this->power->id]);
        Category::factory()->create(['name' => 'CORDLESS DRILL', 'slug' => 'cordless-drill', 'category_group_id' => $this->cordless->id]);
    }

    private function product(string $partNo, Category $category, int $stock = 5, array $attrs = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => 'Item '.$partNo,
            'category_id' => $category->id,
            'group_id' => $category->category_group_id,
        ], $attrs));
        $product->defaultVariant->update(['part_no' => $partNo, 'unit_price' => 1000, 'mrp' => 1000, 'list_price' => 1000, 'current_stock' => $stock]);

        return $product;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function saveLayout(array $sections, ?int $version = null): TestResponse
    {
        $version ??= (int) $this->asAdmin()->getJson('/api/v1/admin/home')->json('version');

        return $this->asAdmin()->putJson('/api/v1/admin/home', ['expected_version' => $version, 'sections' => $sections]);
    }

    private function band(CategoryGroup $group, array $extra = []): array
    {
        return array_merge(['id' => 'band-'.$group->id, 'type' => 'category_band', 'group_id' => $group->id], $extra);
    }

    public function test_default_layout_is_one_band_per_visible_group_with_only_sellable_products(): void
    {
        $sellable = $this->product('PT-1', $this->grinder);
        $this->product('PT-OUT', $this->grinder, 0);
        $this->product('PT-UNPUB', $this->grinder, 5, ['published' => false]);
        $hidden = $this->product('PT-HID', $this->grinder);
        $this->asAdmin()->putJson('/api/v1/admin/catalog/products/'.$hidden->id, ['expected_version' => 0, 'fields' => ['hidden' => true]])->assertOk();

        // Cordless group has no sellable products, so its band is skipped.
        $res = $this->getJson('/api/v1/home')->assertOk();
        $res->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.sections.0.type', 'category_band')
            ->assertJsonPath('data.sections.0.title', 'POWER TOOLS')
            ->assertJsonPath('data.sections.0.view_all_href', '/products?group=power-tools')
            ->assertJsonCount(1, 'data.sections.0.products')
            ->assertJsonPath('data.sections.0.products.0.id', $sellable->id)
            ->assertJsonPath('data.sections.0.featured_categories.0.slug', 'angle-grinder')
            ->assertJsonPath('data.sections.0.featured_categories.0.href', '/products?group=power-tools&category=angle-grinder');

        $this->asAdmin()->getJson('/api/v1/admin/home')->assertOk()
            ->assertJsonPath('is_default', true)
            ->assertJsonPath('version', 0)
            ->assertJsonCount(2, 'sections');
    }

    public function test_pinned_part_nos_lead_in_order_and_bad_pins_are_dropped(): void
    {
        $a = $this->product('PIN-A', $this->grinder);
        $b = $this->product('PIN-B', $this->grinder);
        $this->product('PIN-OUT', $this->grinder, 0);
        $newest = $this->product('AUTO-1', $this->grinder);

        $this->saveLayout([$this->band($this->power, [
            'pinned_part_nos' => ['PIN-B', 'NOPE', 'PIN-OUT', 'PIN-A'],
            'product_limit' => 4,
        ])])->assertOk();

        $ids = collect($this->getJson('/api/v1/home')->json('data.sections.0.products'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id, $newest->id], $ids);
    }

    public function test_save_rejects_unsafe_urls_and_links(): void
    {
        $hero = fn (string $img, ?string $link) => [['id' => 'hero', 'type' => 'hero', 'slides' => [['image_url' => $img, 'link_url' => $link]]]];

        $this->saveLayout($hero('http://cdn.example.com/a.jpg', '/products'))->assertUnprocessable()
            ->assertJsonValidationErrors(['sections.0.slides.0.image_url']);
        $this->saveLayout($hero('data:image/png;base64,AAAA', '/products'))->assertUnprocessable();
        foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,x', 'http://evil.example', '/x y'] as $bad) {
            $this->saveLayout($hero('https://cdn.example.com/a.jpg', $bad))->assertUnprocessable()
                ->assertJsonValidationErrors(['sections.0.slides.0.link_url']);
        }

        $this->saveLayout([['id' => 'row', 'type' => 'banner_row', 'banners' => [[
            'title' => 'Sale', 'href' => 'javascript:void(0)', 'image_url' => 'https://cdn.example.com/b.jpg',
        ]]]])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.banners.0.href']);

        $this->saveLayout([$this->band($this->power, ['group_id' => 999999])])->assertUnprocessable();
        $this->saveLayout([$this->band($this->power), $this->band($this->power)])->assertUnprocessable();

        $this->saveLayout($hero('https://cdn.example.com/a.jpg', 'https://opel.example/offer'))->assertOk();
    }

    public function test_layout_save_is_version_guarded_bumps_cache_and_drives_home(): void
    {
        $this->product('PT-1', $this->grinder);
        $this->getJson('/api/v1/home')->assertJsonCount(1, 'data.sections'); // warm cache
        $before = CatalogCache::version();

        $layout = [
            ['id' => 'hero', 'type' => 'hero', 'slides' => [['image_url' => 'https://cdn.example.com/h.jpg', 'link_url' => '/products']]],
            $this->band($this->power, ['title' => 'Grinders & more', 'hidden' => false]),
            ['id' => 'row', 'type' => 'banner_row', 'hidden' => true, 'banners' => [[
                'title' => 'Hidden', 'href' => '/products', 'image_url' => 'https://cdn.example.com/b.jpg',
            ]]],
        ];

        $this->saveLayout($layout, 0)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('is_default', false);
        $this->assertGreaterThan($before, CatalogCache::version());

        $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.0.type', 'hero')
            ->assertJsonPath('data.sections.1.title', 'Grinders & more');

        $bumped = CatalogCache::version();
        $this->saveLayout([], 0)->assertStatus(409)->assertJsonPath('current.version', 1);
        $this->assertSame($bumped, CatalogCache::version());

        // An empty saved layout is respected (no fallback to the default).
        $this->saveLayout([], 1)->assertOk()->assertJsonPath('version', 2);
        $this->getJson('/api/v1/home')->assertJsonCount(0, 'data.sections');
    }

    public function test_price_override_shows_on_home_without_a_layout_save(): void
    {
        $p = $this->product('PT-1', $this->grinder);
        $this->getJson('/api/v1/home')->assertJsonPath('data.sections.0.products.0.unit_price', '1100.00');

        $this->asAdmin()->putJson('/api/v1/admin/catalog/variants/'.$p->defaultVariant->id, [
            'expected_version' => 0, 'fields' => ['unit_price' => 900],
        ])->assertOk();

        $this->getJson('/api/v1/home')->assertJsonPath('data.sections.0.products.0.unit_price', '900.00');
    }

    public function test_admin_home_routes_need_an_admin(): void
    {
        $this->getJson('/api/v1/admin/home')->assertUnauthorized();
        $this->putJson('/api/v1/admin/home', ['expected_version' => 0, 'sections' => []])->assertUnauthorized();

        $shopper = User::factory()->create();
        $token = $shopper->createToken('opel-b2c')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/home')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/home', ['expected_version' => 0, 'sections' => []])->assertForbidden();
    }
}
