<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'hsncode' => '8708',
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'thumbnail_img' => 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=800&q=80',
            'photos' => [
                'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=1200&q=80',
                'https://images.unsplash.com/photo-1487754180451-c456f719a1fc?auto=format&fit=crop&w=1200&q=80',
            ],
            'description' => fake()->sentence(12),
            'tags' => 'auto,parts',
            'published' => true,
            'approved' => true,
            'unit' => 'pc',
            'min_qty' => 1,
            'piece_per_carton' => 1,
            'synced_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Product $product): void {
            if ($product->group_id) {
                return;
            }

            $category = $product->category_id
                ? Category::query()->find($product->category_id)
                : null;

            if ($category) {
                $product->group_id = $category->category_group_id;
            }
        })->afterCreating(function (Product $product): void {
            if ($product->variants()->exists()) {
                return;
            }

            ProductVariant::factory()->default()->create([
                'product_id' => $product->id,
            ]);
        });
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => false,
        ]);
    }

    public function unapproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved' => false,
        ]);
    }
}
