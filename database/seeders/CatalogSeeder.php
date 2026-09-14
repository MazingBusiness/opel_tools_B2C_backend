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
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Dummy catalog for React filter/sort/PDP testing until the B2B feed exists.
     * Image URLs are Unsplash (https://unsplash.com/license). Hotlinking may 403 later.
     */
    public function run(): void
    {
        $gst = Tax::query()->updateOrCreate(
            ['name' => 'GST'],
            ['rate' => '18.00'],
        );

        $groups = $this->seedGroups();
        $categories = $this->seedCategories($groups);
        $brands = $this->seedBrands();

        foreach ($this->simpleProducts() as $item) {
            $this->seedProduct($item, $categories, $brands, $gst, [
                [
                    'part_no' => $item['part_no'],
                    'label' => null,
                    'options' => [],
                    'unit_price' => $item['unit_price'],
                    'mrp' => $item['mrp'],
                    'list_price' => $item['list_price'],
                    'stock' => $item['stock'],
                    'is_default' => true,
                    'position' => 0,
                    'thumbnail_img' => null,
                ],
            ]);
        }

        foreach ($this->variantProducts() as $item) {
            $this->seedProduct($item, $categories, $brands, $gst, $item['variants']);
        }
    }

    /**
     * @return array<string, CategoryGroup>
     */
    private function seedGroups(): array
    {
        $groups = [];

        foreach ([
            'braking' => 'Braking',
            'engine-fluids' => 'Engine & Fluids',
            'electrical' => 'Electrical',
            'body-cabin' => 'Body & Cabin',
        ] as $slug => $name) {
            $groups[$slug] = CategoryGroup::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true],
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, CategoryGroup>  $groups
     * @return array<string, Category>
     */
    private function seedCategories(array $groups): array
    {
        $categories = [];
        $rows = [
            ['brake-pads', 'Brake Pads', 'braking'],
            ['brake-discs', 'Brake Discs', 'braking'],
            ['engine-oil', 'Engine Oil', 'engine-fluids'],
            ['filters', 'Filters', 'engine-fluids'],
            ['spark-plugs', 'Spark Plugs', 'engine-fluids'],
            ['batteries', 'Batteries', 'electrical'],
            ['lighting', 'Lighting', 'electrical'],
            ['wipers', 'Wipers', 'body-cabin'],
            ['cabin-accessories', 'Cabin Accessories', 'body-cabin'],
            ['floor-mats', 'Floor Mats', 'body-cabin'],
        ];

        foreach ($rows as [$slug, $name, $group]) {
            $categories[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'parent_id' => null,
                    'category_group_id' => $groups[$group]->id,
                    'banner' => $this->image($slug, 1200),
                    'is_active' => true,
                ],
            );
        }

        return $categories;
    }

    /**
     * @return array<string, Brand>
     */
    private function seedBrands(): array
    {
        $brands = [];
        $rows = [
            'bosch' => 'Bosch',
            'tvs-girling' => 'TVS Girling',
            'castrol' => 'Castrol',
            'amaron' => 'Amaron',
            'osram' => 'Osram',
            'valeo' => 'Valeo',
            'mann-filter' => 'Mann Filter',
            'opel' => 'OPEL',
        ];

        foreach ($rows as $slug => $name) {
            $brands[$slug] = Brand::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'logo' => $this->image('brand-'.$slug, 200),
                    'is_active' => true,
                ],
            );
        }

        return $brands;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function simpleProducts(): array
    {
        return [
            ['name' => 'Bosch Ceramic Front Brake Pad', 'slug' => 'bosch-ceramic-front-brake-pad', 'category' => 'brake-pads', 'brand' => 'bosch', 'part_no' => 'OPL-BRK-0001', 'unit_price' => 1899, 'mrp' => 2499, 'list_price' => 1799, 'stock' => 28, 'hsncode' => '87083000', 'tags' => 'brake,pads,ceramic'],
            ['name' => 'Bosch Semi-Metallic Rear Brake Pad', 'slug' => 'bosch-semi-metallic-rear-brake-pad', 'category' => 'brake-pads', 'brand' => 'bosch', 'part_no' => 'OPL-BRK-0002', 'unit_price' => 1299, 'mrp' => 1699, 'list_price' => 1199, 'stock' => 14, 'hsncode' => '87083000', 'tags' => 'brake,pads'],
            ['name' => 'TVS Girling Front Brake Pad Set', 'slug' => 'tvs-girling-front-brake-pad-set', 'category' => 'brake-pads', 'brand' => 'tvs-girling', 'part_no' => 'OPL-BRK-0003', 'unit_price' => 999, 'mrp' => 1399, 'list_price' => 949, 'stock' => 40, 'hsncode' => '87083000', 'tags' => 'brake,pads'],
            ['name' => 'TVS Girling Rear Brake Pad Set', 'slug' => 'tvs-girling-rear-brake-pad-set', 'category' => 'brake-pads', 'brand' => 'tvs-girling', 'part_no' => 'OPL-BRK-0004', 'unit_price' => 849, 'mrp' => 1199, 'list_price' => 799, 'stock' => 0, 'hsncode' => '87083000', 'tags' => 'brake,pads'],
            ['name' => 'OPEL Performance Brake Pad', 'slug' => 'opel-performance-brake-pad', 'category' => 'brake-pads', 'brand' => 'opel', 'part_no' => 'OPL-BRK-0005', 'unit_price' => 1599, 'mrp' => 1999, 'list_price' => 1499, 'stock' => 18, 'hsncode' => '87083000', 'tags' => 'brake,pads,opel'],
            ['name' => 'Bosch Vented Front Brake Disc', 'slug' => 'bosch-vented-front-brake-disc', 'category' => 'brake-discs', 'brand' => 'bosch', 'part_no' => 'OPL-BRK-0101', 'unit_price' => 3299, 'mrp' => 4199, 'list_price' => 3099, 'stock' => 12, 'hsncode' => '87083000', 'tags' => 'brake,disc'],
            ['name' => 'TVS Girling Solid Rear Brake Disc', 'slug' => 'tvs-girling-solid-rear-brake-disc', 'category' => 'brake-discs', 'brand' => 'tvs-girling', 'part_no' => 'OPL-BRK-0102', 'unit_price' => 2199, 'mrp' => 2799, 'list_price' => 2099, 'stock' => 9, 'hsncode' => '87083000', 'tags' => 'brake,disc'],
            ['name' => 'TVS Girling Cross-Drilled Disc', 'slug' => 'tvs-girling-cross-drilled-disc', 'category' => 'brake-discs', 'brand' => 'tvs-girling', 'part_no' => 'OPL-BRK-0103', 'unit_price' => 4599, 'mrp' => 5499, 'list_price' => 4399, 'stock' => 6, 'hsncode' => '87083000', 'tags' => 'brake,disc'],
            ['name' => 'OPEL Coated Brake Disc Pair', 'slug' => 'opel-coated-brake-disc-pair', 'category' => 'brake-discs', 'brand' => 'opel', 'part_no' => 'OPL-BRK-0104', 'unit_price' => 3899, 'mrp' => 4699, 'list_price' => 3699, 'stock' => 11, 'hsncode' => '87083000', 'tags' => 'brake,disc,opel'],
            ['name' => 'OPEL Mineral Engine Oil 1L', 'slug' => 'opel-mineral-engine-oil-1l', 'category' => 'engine-oil', 'brand' => 'opel', 'part_no' => 'OPL-OIL-0008', 'unit_price' => 349, 'mrp' => 449, 'list_price' => 329, 'stock' => 60, 'hsncode' => '27101980', 'tags' => 'oil,engine'],
            ['name' => 'OPEL Synthetic Blend Engine Oil 4L', 'slug' => 'opel-synthetic-blend-engine-oil-4l', 'category' => 'engine-oil', 'brand' => 'opel', 'part_no' => 'OPL-OIL-0009', 'unit_price' => 1299, 'mrp' => 1699, 'list_price' => 1199, 'stock' => 22, 'hsncode' => '27101980', 'tags' => 'oil,engine'],
            ['name' => 'Mann Filter Oil Filter W 712', 'slug' => 'mann-filter-oil-filter-w712', 'category' => 'filters', 'brand' => 'mann-filter', 'part_no' => 'OPL-FLT-0001', 'unit_price' => 449, 'mrp' => 599, 'list_price' => 429, 'stock' => 50, 'hsncode' => '84212300', 'tags' => 'filter,oil'],
            ['name' => 'Mann Filter Air Filter C 27009', 'slug' => 'mann-filter-air-filter-c27009', 'category' => 'filters', 'brand' => 'mann-filter', 'part_no' => 'OPL-FLT-0002', 'unit_price' => 799, 'mrp' => 999, 'list_price' => 749, 'stock' => 33, 'hsncode' => '84213100', 'tags' => 'filter,air'],
            ['name' => 'Mann Filter Cabin Filter CU 2939', 'slug' => 'mann-filter-cabin-filter-cu2939', 'category' => 'filters', 'brand' => 'mann-filter', 'part_no' => 'OPL-FLT-0003', 'unit_price' => 649, 'mrp' => 849, 'list_price' => 599, 'stock' => 0, 'hsncode' => '84213990', 'tags' => 'filter,cabin'],
            ['name' => 'Bosch Oil Filter P 3264', 'slug' => 'bosch-oil-filter-p3264', 'category' => 'filters', 'brand' => 'bosch', 'part_no' => 'OPL-FLT-0004', 'unit_price' => 399, 'mrp' => 549, 'list_price' => 379, 'stock' => 44, 'hsncode' => '84212300', 'tags' => 'filter,oil'],
            ['name' => 'Bosch Air Filter S 0121', 'slug' => 'bosch-air-filter-s0121', 'category' => 'filters', 'brand' => 'bosch', 'part_no' => 'OPL-FLT-0005', 'unit_price' => 699, 'mrp' => 899, 'list_price' => 659, 'stock' => 27, 'hsncode' => '84213100', 'tags' => 'filter,air'],
            ['name' => 'Bosch Iridium Spark Plug FR7NPP30X', 'slug' => 'bosch-iridium-spark-plug', 'category' => 'spark-plugs', 'brand' => 'bosch', 'part_no' => 'OPL-SPK-0001', 'unit_price' => 549, 'mrp' => 749, 'list_price' => 499, 'stock' => 80, 'hsncode' => '85111000', 'tags' => 'spark,plug'],
            ['name' => 'Bosch Super Plus Spark Plug WR7DC+', 'slug' => 'bosch-super-plus-spark-plug', 'category' => 'spark-plugs', 'brand' => 'bosch', 'part_no' => 'OPL-SPK-0002', 'unit_price' => 249, 'mrp' => 349, 'list_price' => 229, 'stock' => 90, 'hsncode' => '85111000', 'tags' => 'spark,plug'],
            ['name' => 'OPEL Copper Spark Plug Set', 'slug' => 'opel-copper-spark-plug-set', 'category' => 'spark-plugs', 'brand' => 'opel', 'part_no' => 'OPL-SPK-0003', 'unit_price' => 399, 'mrp' => 549, 'list_price' => 379, 'stock' => 35, 'hsncode' => '85111000', 'tags' => 'spark,plug,opel'],
            ['name' => 'OPEL Maintenance-Free Battery 32Ah', 'slug' => 'opel-maintenance-free-battery-32ah', 'category' => 'batteries', 'brand' => 'opel', 'part_no' => 'OPL-BAT-0008', 'unit_price' => 2899, 'mrp' => 3499, 'list_price' => 2699, 'stock' => 7, 'hsncode' => '85071000', 'tags' => 'battery'],
            ['name' => 'Osram Night Breaker 200 H4', 'slug' => 'osram-night-breaker-200-h4', 'category' => 'lighting', 'brand' => 'osram', 'part_no' => 'OPL-LGT-0001', 'unit_price' => 1299, 'mrp' => 1699, 'list_price' => 1199, 'stock' => 25, 'hsncode' => '85392190', 'tags' => 'lighting,headlamp'],
            ['name' => 'Osram Cool Blue Intense H7', 'slug' => 'osram-cool-blue-intense-h7', 'category' => 'lighting', 'brand' => 'osram', 'part_no' => 'OPL-LGT-0002', 'unit_price' => 1499, 'mrp' => 1899, 'list_price' => 1399, 'stock' => 19, 'hsncode' => '85392190', 'tags' => 'lighting,headlamp'],
            ['name' => 'Osram LEDriving HL H11', 'slug' => 'osram-ledriving-hl-h11', 'category' => 'lighting', 'brand' => 'osram', 'part_no' => 'OPL-LGT-0003', 'unit_price' => 4299, 'mrp' => 5299, 'list_price' => 4099, 'stock' => 8, 'hsncode' => '85122000', 'tags' => 'lighting,led'],
            ['name' => 'Valeo Halogen Headlamp H4', 'slug' => 'valeo-halogen-headlamp-h4', 'category' => 'lighting', 'brand' => 'valeo', 'part_no' => 'OPL-LGT-0004', 'unit_price' => 899, 'mrp' => 1199, 'list_price' => 849, 'stock' => 0, 'hsncode' => '85392190', 'tags' => 'lighting'],
            ['name' => 'Valeo Indicator Bulb PY21W', 'slug' => 'valeo-indicator-bulb-py21w', 'category' => 'lighting', 'brand' => 'valeo', 'part_no' => 'OPL-LGT-0005', 'unit_price' => 199, 'mrp' => 299, 'list_price' => 179, 'stock' => 120, 'hsncode' => '85392190', 'tags' => 'lighting,bulb'],
            ['name' => 'Bosch Aerotwin Wiper Blade 22in', 'slug' => 'bosch-aerotwin-wiper-blade-22in', 'category' => 'wipers', 'brand' => 'bosch', 'part_no' => 'OPL-WPR-0008', 'unit_price' => 799, 'mrp' => 999, 'list_price' => 749, 'stock' => 30, 'hsncode' => '85129000', 'tags' => 'wiper'],
            ['name' => 'Bosch Rear Wiper Blade 14in', 'slug' => 'bosch-rear-wiper-blade-14in', 'category' => 'wipers', 'brand' => 'bosch', 'part_no' => 'OPL-WPR-0009', 'unit_price' => 449, 'mrp' => 599, 'list_price' => 429, 'stock' => 21, 'hsncode' => '85129000', 'tags' => 'wiper'],
            ['name' => 'OPEL Cabin Air Freshener Pack', 'slug' => 'opel-cabin-air-freshener-pack', 'category' => 'cabin-accessories', 'brand' => 'opel', 'part_no' => 'OPL-CAB-0001', 'unit_price' => 199, 'mrp' => 299, 'list_price' => 179, 'stock' => 70, 'hsncode' => '33074900', 'tags' => 'cabin'],
            ['name' => 'OPEL Seat Gap Organizer', 'slug' => 'opel-seat-gap-organizer', 'category' => 'cabin-accessories', 'brand' => 'opel', 'part_no' => 'OPL-CAB-0002', 'unit_price' => 549, 'mrp' => 799, 'list_price' => 499, 'stock' => 16, 'hsncode' => '87089900', 'tags' => 'cabin'],
            ['name' => 'OPEL Steering Wheel Cover', 'slug' => 'opel-steering-wheel-cover', 'category' => 'cabin-accessories', 'brand' => 'opel', 'part_no' => 'OPL-CAB-0003', 'unit_price' => 699, 'mrp' => 999, 'list_price' => 649, 'stock' => 13, 'hsncode' => '87089900', 'tags' => 'cabin'],
            ['name' => 'Bosch Cabin Comfort Kit', 'slug' => 'bosch-cabin-comfort-kit', 'category' => 'cabin-accessories', 'brand' => 'bosch', 'part_no' => 'OPL-CAB-0004', 'unit_price' => 1199, 'mrp' => 1499, 'list_price' => 1099, 'stock' => 10, 'hsncode' => '87089900', 'tags' => 'cabin'],
            ['name' => 'OPEL Rubber Boot Mat', 'slug' => 'opel-rubber-boot-mat', 'category' => 'floor-mats', 'brand' => 'opel', 'part_no' => 'OPL-MAT-0008', 'unit_price' => 899, 'mrp' => 1299, 'list_price' => 849, 'stock' => 15, 'hsncode' => '40169100', 'tags' => 'mat'],
            ['name' => 'Valeo Universal Wiper Adapter Kit', 'slug' => 'valeo-universal-wiper-adapter-kit', 'category' => 'wipers', 'brand' => 'valeo', 'part_no' => 'OPL-WPR-0010', 'unit_price' => 249, 'mrp' => 349, 'list_price' => 229, 'stock' => 0, 'hsncode' => '85129000', 'tags' => 'wiper'],
            ['name' => 'Mann Filter Fuel Filter WK 842/2', 'slug' => 'mann-filter-fuel-filter-wk842', 'category' => 'filters', 'brand' => 'mann-filter', 'part_no' => 'OPL-FLT-0006', 'unit_price' => 899, 'mrp' => 1199, 'list_price' => 849, 'stock' => 17, 'hsncode' => '84212300', 'tags' => 'filter,fuel'],
            ['name' => 'Osram Fog Breaker H8', 'slug' => 'osram-fog-breaker-h8', 'category' => 'lighting', 'brand' => 'osram', 'part_no' => 'OPL-LGT-0006', 'unit_price' => 999, 'mrp' => 1299, 'list_price' => 949, 'stock' => 14, 'hsncode' => '85392190', 'tags' => 'lighting,fog'],
            ['name' => 'TVS Girling Brake Shoe Set', 'slug' => 'tvs-girling-brake-shoe-set', 'category' => 'brake-pads', 'brand' => 'tvs-girling', 'part_no' => 'OPL-BRK-0006', 'unit_price' => 749, 'mrp' => 999, 'list_price' => 699, 'stock' => 20, 'hsncode' => '87083000', 'tags' => 'brake,shoe'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variantProducts(): array
    {
        return [
            [
                'name' => 'Castrol MAGNATEC 5W-30 Engine Oil',
                'slug' => 'castrol-magnatec-5w30-engine-oil',
                'category' => 'engine-oil',
                'brand' => 'castrol',
                'hsncode' => '27101980',
                'tags' => 'oil,engine,castrol',
                'variants' => [
                    ['part_no' => 'OPL-OIL-0001', 'label' => '1 Litre', 'options' => ['Volume' => '1L'], 'unit_price' => 649, 'mrp' => 799, 'list_price' => 599, 'stock' => 40, 'is_default' => true, 'position' => 0, 'thumbnail_img' => $this->image('oil-1l', 800)],
                    ['part_no' => 'OPL-OIL-0004', 'label' => '4 Litre', 'options' => ['Volume' => '4L'], 'unit_price' => 2299, 'mrp' => 2799, 'list_price' => 2199, 'stock' => 18, 'is_default' => false, 'position' => 1, 'thumbnail_img' => $this->image('oil-4l', 800)],
                    ['part_no' => 'OPL-OIL-0005', 'label' => '5 Litre', 'options' => ['Volume' => '5L'], 'unit_price' => 2699, 'mrp' => 3299, 'list_price' => 2599, 'stock' => 0, 'is_default' => false, 'position' => 2, 'thumbnail_img' => $this->image('oil-5l', 800)],
                ],
            ],
            [
                'name' => 'Amaron FLO Battery',
                'slug' => 'amaron-flo-battery',
                'category' => 'batteries',
                'brand' => 'amaron',
                'hsncode' => '85071000',
                'tags' => 'battery,amaron',
                'variants' => [
                    ['part_no' => 'OPL-BAT-0035', 'label' => '35 Ah', 'options' => ['Capacity' => '35Ah'], 'unit_price' => 4299, 'mrp' => 5199, 'list_price' => 4099, 'stock' => 8, 'is_default' => true, 'position' => 0, 'thumbnail_img' => $this->image('battery-35', 800)],
                    ['part_no' => 'OPL-BAT-0045', 'label' => '45 Ah', 'options' => ['Capacity' => '45Ah'], 'unit_price' => 5899, 'mrp' => 6999, 'list_price' => 5599, 'stock' => 5, 'is_default' => false, 'position' => 1, 'thumbnail_img' => $this->image('battery-45', 800)],
                    ['part_no' => 'OPL-BAT-0060', 'label' => '60 Ah', 'options' => ['Capacity' => '60Ah'], 'unit_price' => 8199, 'mrp' => 9699, 'list_price' => 7899, 'stock' => 3, 'is_default' => false, 'position' => 2, 'thumbnail_img' => $this->image('battery-60', 800)],
                ],
            ],
            [
                'name' => 'Valeo Silencio Wiper Blade',
                'slug' => 'valeo-silencio-wiper-blade',
                'category' => 'wipers',
                'brand' => 'valeo',
                'hsncode' => '85129000',
                'tags' => 'wiper,valeo',
                'variants' => [
                    ['part_no' => 'OPL-WPR-0016', 'label' => '16 inch', 'options' => ['Size' => '16in'], 'unit_price' => 549, 'mrp' => 699, 'list_price' => 499, 'stock' => 24, 'is_default' => false, 'position' => 0, 'thumbnail_img' => null],
                    ['part_no' => 'OPL-WPR-0020', 'label' => '20 inch', 'options' => ['Size' => '20in'], 'unit_price' => 649, 'mrp' => 799, 'list_price' => 599, 'stock' => 20, 'is_default' => true, 'position' => 1, 'thumbnail_img' => null],
                    ['part_no' => 'OPL-WPR-0024', 'label' => '24 inch', 'options' => ['Size' => '24in'], 'unit_price' => 749, 'mrp' => 899, 'list_price' => 699, 'stock' => 12, 'is_default' => false, 'position' => 2, 'thumbnail_img' => null],
                ],
            ],
            [
                'name' => 'OPEL Premium Floor Mat Set',
                'slug' => 'opel-premium-floor-mat-set',
                'category' => 'floor-mats',
                'brand' => 'opel',
                'hsncode' => '40169100',
                'tags' => 'mat,cabin,opel',
                'variants' => [
                    ['part_no' => 'OPL-MAT-BLK', 'label' => 'Black', 'options' => ['Color' => 'Black'], 'unit_price' => 1499, 'mrp' => 1999, 'list_price' => 1399, 'stock' => 11, 'is_default' => true, 'position' => 0, 'thumbnail_img' => $this->image('mat-black', 800)],
                    ['part_no' => 'OPL-MAT-BGE', 'label' => 'Beige', 'options' => ['Color' => 'Beige'], 'unit_price' => 1499, 'mrp' => 1999, 'list_price' => 1399, 'stock' => 4, 'is_default' => false, 'position' => 1, 'thumbnail_img' => $this->image('mat-beige', 800)],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     * @param  list<array<string, mixed>>  $variants
     */
    private function seedProduct(array $item, array $categories, array $brands, Tax $gst, array $variants): void
    {
        $category = $categories[$item['category']];
        $thumb = $this->image($item['slug'], 800);

        $product = Product::query()->updateOrCreate(
            ['slug' => $item['slug']],
            [
                'name' => $item['name'],
                'hsncode' => $item['hsncode'],
                'group_id' => $category->category_group_id,
                'category_id' => $category->id,
                'brand_id' => $brands[$item['brand']]->id,
                'thumbnail_img' => $thumb,
                'photos' => [
                    $thumb,
                    $this->image($item['slug'].'-2', 1200),
                    $this->image($item['slug'].'-3', 1200),
                ],
                'description' => $item['name'].' for the OPEL B2C shop. Dummy catalog item until B2B sync.',
                'tags' => $item['tags'],
                'published' => true,
                'approved' => true,
                'unit' => 'pc',
                'min_qty' => 1,
                'piece_per_carton' => 1,
            ],
        );

        ProductTax::query()->updateOrCreate(
            ['product_id' => $product->id, 'tax_id' => $gst->id],
            ['tax' => $gst->rate, 'tax_type' => 'percent'],
        );

        foreach ($variants as $variantData) {
            $variant = ProductVariant::query()->updateOrCreate(
                ['part_no' => $variantData['part_no']],
                [
                    'product_id' => $product->id,
                    'part_no_normalized' => Str::lower(preg_replace('/[^a-zA-Z0-9]/', '', $variantData['part_no'])),
                    'options' => $variantData['options'],
                    'label' => $variantData['label'],
                    'thumbnail_img' => $variantData['thumbnail_img'],
                    'unit_price' => $variantData['unit_price'],
                    'list_price' => $variantData['list_price'],
                    'mrp' => $variantData['mrp'],
                    'carton_price' => null,
                    'current_stock' => $variantData['stock'],
                    'min_qty' => 1,
                    'hsncode' => null,
                    'is_default' => $variantData['is_default'],
                    'position' => $variantData['position'],
                ],
            );

            ProductWarehouse::query()->updateOrCreate(
                [
                    'product_variant_id' => $variant->id,
                    'warehouse_code' => 'DELHI',
                ],
                [
                    'qty' => $variantData['stock'],
                    'price' => $variantData['unit_price'],
                ],
            );
        }
    }

    private function image(string $seed, int $size): string
    {
        $photos = [
            'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1487754180451-c456f719a1fc?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1593941707882-a5bba14938c7?auto=format&fit=crop&w=%d&q=80',
            'https://images.unsplash.com/photo-1489824904134-891ab6453f24?auto=format&fit=crop&w=%d&q=80',
        ];

        $index = abs(crc32($seed)) % count($photos);

        return sprintf($photos[$index], $size);
    }
}
