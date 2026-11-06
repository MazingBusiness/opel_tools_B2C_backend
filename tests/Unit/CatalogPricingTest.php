<?php

namespace Tests\Unit;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CatalogPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_markup_rounds_half_up_and_override_is_not_marked_up(): void
    {
        $this->assertSame('1226.64', CatalogPricing::pricingLogic('1115.13'));
        $this->assertSame('1160.50', CatalogPricing::pricingLogic('1055'));
        $this->assertSame('750.00', CatalogPricing::effective('1115.13', '750.00'));
        $this->assertSame('1226.64', CatalogPricing::effective('1115.13', null));
    }

    public function test_markup_1115_13_is_1226_64_on_php_and_sql(): void
    {
        $variant = ProductVariant::factory()->create([
            'unit_price' => 1115.13,
            'list_price' => 1115.13,
            'mrp' => 1115.13,
        ]);

        $this->assertSame('1226.64', CatalogPricing::pricingLogic('1115.13'));
        $this->assertEqualsWithDelta(1226.64, (float) $variant->fresh()->unit_price, 0.001);
        $this->assertEqualsWithDelta(1226.64, (float) $variant->fresh()->mrp, 0.001);
        $this->assertEqualsWithDelta(1226.64, (float) $variant->fresh()->list_price, 0.001);

        $sql = ProductVariant::effectivePriceSql('product_variants');
        $value = DB::table('product_variants')->where('id', $variant->id)->value(DB::raw($sql.' as price'));
        $this->assertEqualsWithDelta(1226.64, (float) $value, 0.001);
    }
}
