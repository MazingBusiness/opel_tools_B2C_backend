<?php

namespace App\Modules\Wishlist\Http\Resources;

use App\Modules\Catalog\Http\Resources\BrandResource;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Live-joined catalog fields (not snapshotted at add-time).
 */
class WishlistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $available = $product !== null
            && (bool) $product->published
            && (bool) $product->approved;

        $variant = null;
        $inStock = false;
        $variantsCount = 0;

        if ($available && $product) {
            $variant = $product->relationLoaded('defaultVariant')
                ? $product->defaultVariant
                : null;

            $variantsCount = (int) ($product->variants_count ?? 0);
            $inStock = (bool) ($product->in_stock ?? false);
        }

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'added_at' => $this->created_at?->toIso8601String(),
            'available' => $available,
            'product' => $available && $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'thumbnail_img' => $product->thumbnail_img,
                'in_stock' => $inStock,
                'has_variants' => $variantsCount > 1,
                'brand' => $product->relationLoaded('brand')
                    ? new BrandResource($product->brand)
                    : null,
                'category' => $product->relationLoaded('category')
                    ? new CategoryResource($product->category)
                    : null,
                'group' => $product->relationLoaded('categoryGroup') && $product->categoryGroup
                    ? [
                        'id' => $product->categoryGroup->id,
                        'name' => $product->categoryGroup->name,
                        'slug' => $product->categoryGroup->slug,
                    ]
                    : null,
                'variant_id' => $variant?->id,
                'part_no' => $variant?->part_no,
                'unit_price' => $variant?->unit_price,
                'list_price' => $variant?->list_price,
                'mrp' => $variant?->mrp,
                'current_stock' => $variant?->current_stock,
            ] : null,
        ];
    }
}
