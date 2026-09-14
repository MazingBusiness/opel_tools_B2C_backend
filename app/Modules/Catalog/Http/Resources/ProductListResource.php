<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variant = $this->defaultVariant;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'thumbnail_img' => $this->thumbnail_img,
            'has_variants' => ($this->variants_count ?? 0) > 1,
            'in_stock' => (bool) $this->in_stock,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'group' => $this->whenLoaded('categoryGroup', fn () => [
                'id' => $this->categoryGroup->id,
                'name' => $this->categoryGroup->name,
                'slug' => $this->categoryGroup->slug,
            ]),
            'variant_id' => $variant?->id,
            'part_no' => $variant?->part_no,
            'unit_price' => $variant?->unit_price,
            'list_price' => $variant?->list_price,
            'mrp' => $variant?->mrp,
            'current_stock' => $variant?->current_stock,
            'matched_variant_id' => $this->matched_variant_id,
        ];
    }
}
