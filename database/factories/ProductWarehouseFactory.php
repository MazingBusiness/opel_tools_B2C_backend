<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\ProductWarehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductWarehouse>
 */
class ProductWarehouseFactory extends Factory
{
    protected $model = ProductWarehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_code' => 'DELHI',
            'qty' => fake()->numberBetween(0, 80),
            'price' => fake()->randomFloat(2, 150, 12000),
        ];
    }
}
