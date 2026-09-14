<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\ProductWarehouse;
use App\Modules\Catalog\Support\PartNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $partNo = 'OPL-'.fake()->unique()->bothify('??-####');
        $price = fake()->randomFloat(2, 150, 12000);

        return [
            'product_id' => Product::factory(),
            'part_no' => $partNo,
            'part_no_normalized' => PartNumber::normalize($partNo),
            'options' => [],
            'label' => null,
            'thumbnail_img' => null,
            'unit_price' => $price,
            'list_price' => round($price * 0.95, 2),
            'mrp' => round($price * 1.2, 2),
            'carton_price' => null,
            'current_stock' => fake()->numberBetween(5, 80),
            'min_qty' => 1,
            'hsncode' => null,
            'is_default' => false,
            'position' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProductVariant $variant): void {
            if ($variant->warehouses()->exists()) {
                return;
            }

            ProductWarehouse::factory()->create([
                'product_variant_id' => $variant->id,
                'qty' => $variant->current_stock,
                'price' => $variant->unit_price,
            ]);
        });
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'position' => 0,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_stock' => 0,
        ]);
    }
}
