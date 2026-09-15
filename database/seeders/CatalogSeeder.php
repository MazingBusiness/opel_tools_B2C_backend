<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTax;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\ProductWarehouse;
use App\Modules\Catalog\Models\Tax;
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
     * @var array<string, Brand>
     */
    private array $brands = [];

    /**
     * Original B2B AIR BLOWER catalog (pages 1–3) until the product feed exists.
     */
    public function run(): void
    {
        $this->wipeCatalog();

        $gst = Tax::query()->create([
            'name' => 'GST',
            'rate' => '18.00',
        ]);

        $group = CategoryGroup::query()->create([
            'name' => 'POWER TOOLS',
            'slug' => 'power-tools',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'AIR BLOWER',
            'slug' => 'air-blower',
            'parent_id' => null,
            'category_group_id' => $group->id,
            'banner' => null,
            'is_active' => true,
        ]);

        foreach ($this->products() as $item) {
            $this->seedProduct($item, $group, $category, $gst);
        }
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
     * @param  array<string, mixed>  $item
     */
    private function seedProduct(array $item, CategoryGroup $group, Category $category, Tax $gst): void
    {
        $photos = $this->photoUrls($item);
        $thumb = $this->httpUrl(data_get($item, 'thumb_img.file_name'));
        $mrp = $item['mrp'];
        $stockByCode = $this->stockByCode($item);
        $brand = $this->brandFor((string) $item['name']);

        $product = Product::query()->create([
            'name' => $item['name'],
            'slug' => $item['slug'],
            'hsncode' => null,
            'group_id' => $group->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'thumbnail_img' => $thumb,
            'photos' => $photos === [] ? null : $photos,
            'description' => $item['name'],
            'tags' => 'air-blower,power-tools',
            'published' => true,
            'approved' => true,
            'unit' => 'pc',
            'min_qty' => 1,
            'piece_per_carton' => 1,
            'synced_at' => now(),
        ]);

        ProductTax::query()->create([
            'product_id' => $product->id,
            'tax_id' => $gst->id,
            'tax' => $gst->rate,
            'tax_type' => 'percent',
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'part_no' => $item['part_no'],
            'options' => [],
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
        ]);

        foreach ($stockByCode as $code => $qty) {
            ProductWarehouse::query()->create([
                'product_variant_id' => $variant->id,
                'warehouse_code' => $code,
                'qty' => $qty,
                'price' => $mrp,
            ]);
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

    private function brandFor(string $productName): Brand
    {
        $name = $this->brandName($productName);
        $slug = Str::slug($name);

        return $this->brands[$slug] ??= Brand::query()->create([
            'name' => $name,
            'slug' => $slug,
            'logo' => null,
            'is_active' => true,
        ]);
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

    private function wipeCatalog(): void
    {
        Schema::disableForeignKeyConstraints();

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
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }
}
