<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_groups_include_nested_categories(): void
    {
        $group = CategoryGroup::factory()->create(['name' => 'Braking', 'slug' => 'braking']);
        Category::factory()->create([
            'name' => 'Brake Pads',
            'slug' => 'brake-pads',
            'category_group_id' => $group->id,
        ]);

        $this->getJson('/api/v1/category-groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Braking')
            ->assertJsonPath('data.0.categories.0.slug', 'brake-pads');
    }

    public function test_unpublished_products_are_hidden_from_list_and_pdp(): void
    {
        $visible = Product::factory()->create(['name' => 'Visible Pad', 'slug' => 'visible-pad']);
        $hidden = Product::factory()->unpublished()->create(['name' => 'Hidden Pad', 'slug' => 'hidden-pad']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson('/api/v1/products/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/products/'.$hidden->slug)->assertNotFound();
    }

    public function test_unapproved_products_are_hidden(): void
    {
        Product::factory()->unapproved()->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_matches_variant_part_no_and_product_name(): void
    {
        $byName = Product::factory()->create(['name' => 'Ceramic Brake Pad', 'slug' => 'ceramic-brake-pad']);
        $byPart = Product::factory()->create(['name' => 'Oil Filter', 'slug' => 'oil-filter']);
        $byPart->defaultVariant->update(['part_no' => 'OPX-TEST-99']);

        $this->getJson('/api/v1/products?q=Ceramic')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byName->id);

        $this->getJson('/api/v1/products?q=OPX-TEST-99')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byPart->id)
            ->assertJsonPath('data.0.matched_variant_id', $byPart->defaultVariant->id);
    }

    public function test_filters_and_sort_and_in_stock(): void
    {
        $group = CategoryGroup::factory()->create();
        $pads = Category::factory()->create(['category_group_id' => $group->id]);
        $oil = Category::factory()->create(['category_group_id' => $group->id]);
        $bosch = Brand::factory()->create(['name' => 'Bosch']);
        $castrol = Brand::factory()->create(['name' => 'Castrol']);

        $cheap = Product::factory()->create([
            'name' => 'Cheap Pad',
            'slug' => 'cheap-pad',
            'group_id' => $group->id,
            'category_id' => $pads->id,
            'brand_id' => $bosch->id,
        ]);
        $cheap->defaultVariant->update(['unit_price' => 500, 'current_stock' => 4]);

        $pricey = Product::factory()->create([
            'name' => 'Pricey Pad',
            'slug' => 'pricey-pad',
            'group_id' => $group->id,
            'category_id' => $pads->id,
            'brand_id' => $bosch->id,
        ]);
        $pricey->defaultVariant->update(['unit_price' => 2500, 'current_stock' => 0]);
        $pricey->defaultVariant->warehouses()->update(['qty' => 0]);

        $other = Product::factory()->create([
            'name' => 'Engine Oil',
            'slug' => 'engine-oil',
            'group_id' => $group->id,
            'category_id' => $oil->id,
            'brand_id' => $castrol->id,
        ]);
        $other->defaultVariant->update(['unit_price' => 900, 'current_stock' => 10]);

        $this->getJson('/api/v1/products?category_id='.$pads->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/products?brand_id='.$bosch->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/products?min=400&max=1000')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/products?sort=price_low_to_high')
            ->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id)
            ->assertJsonPath('data.2.id', $pricey->id);

        $this->getJson('/api/v1/products?sort=price_high_to_low')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pricey->id);

        $this->getJson('/api/v1/products?group_id='.$group->id)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['facets' => ['groups', 'categories', 'brands', 'min_price', 'max_price']]);
    }

    public function test_pdp_by_id_and_slug_includes_variants_and_option_groups(): void
    {
        $product = Product::factory()->create([
            'name' => 'Castrol MAGNATEC',
            'slug' => 'castrol-magnatec',
        ]);
        $product->defaultVariant->update([
            'part_no' => 'OPL-OIL-0001',
            'label' => '1 Litre',
            'options' => ['Volume' => '1L'],
            'is_default' => true,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'part_no' => 'OPL-OIL-0004',
            'label' => '4 Litre',
            'options' => ['Volume' => '4L'],
            'is_default' => false,
            'position' => 1,
        ]);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.slug', 'castrol-magnatec')
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.option_groups.0.name', 'Volume')
            ->assertJsonPath('data.has_variants', true);

        $this->getJson('/api/v1/products/castrol-magnatec')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_simple_product_has_one_default_variant(): void
    {
        $product = Product::factory()->create(['slug' => 'simple-pad']);

        $this->getJson('/api/v1/products/simple-pad')
            ->assertOk()
            ->assertJsonPath('data.has_variants', false)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.is_default', true)
            ->assertJsonPath('data.option_groups', []);
    }

    public function test_catalog_responses_send_cache_control(): void
    {
        $product = Product::factory()->create();

        $list = $this->getJson('/api/v1/products');
        $list->assertOk();
        $this->assertStringContainsString('max-age=60', (string) $list->headers->get('Cache-Control'));

        $show = $this->getJson('/api/v1/products/'.$product->slug);
        $show->assertOk();
        $this->assertStringContainsString('max-age=60', (string) $show->headers->get('Cache-Control'));
    }

    public function test_brands_and_categories_endpoints(): void
    {
        Brand::factory()->create(['name' => 'Bosch', 'slug' => 'bosch']);
        Category::factory()->create(['name' => 'Filters', 'slug' => 'filters']);

        $this->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bosch');

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Filters');
    }

    public function test_catalog_seeder_covers_taxonomy_and_variants(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame(1, CategoryGroup::query()->count());
        $this->assertSame(1, Category::query()->count());
        $this->assertSame('POWER TOOLS', CategoryGroup::query()->value('name'));
        $this->assertSame('AIR BLOWER', Category::query()->value('name'));
        $this->assertSame(45, Product::query()->count());
        $this->assertSame(45, ProductVariant::query()->count());
        $this->assertFalse(Product::query()->has('variants', '>', 1)->exists());

        $opel = ProductVariant::query()->where('part_no', 'MZ11018')->first();
        $this->assertNotNull($opel);
        $this->assertEquals(1055, (float) $opel->unit_price);
        $this->assertEquals(1055, (float) $opel->mrp);
        $this->assertEquals(372, $opel->current_stock);
        $this->assertStringContainsString(
            'mazingbusiness.com/public/uploads/all/thumb_/',
            (string) $opel->product->thumbnail_img,
        );

        $withoutImage = Product::query()->where('slug', 'axtrim-pro--blue-series--electric-blower---axpt-322')->first();
        $this->assertNotNull($withoutImage);
        $this->assertNull($withoutImage->thumbnail_img);
        $this->assertNull($withoutImage->photos);
        $this->assertSame('MZ14890', $withoutImage->defaultVariant->part_no);

        $amber = ProductVariant::query()->where('part_no', 'MZ07868')->first();
        $this->assertSame(113, $amber->current_stock);
        $this->assertSame(1, $amber->warehouses()->count());

        $this->getJson('/api/v1/products?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 45);
    }
}
