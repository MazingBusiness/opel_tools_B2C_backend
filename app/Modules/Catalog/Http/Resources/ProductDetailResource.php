<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $anyInStock = $this->variants->contains(fn ($variant): bool => $variant->current_stock > 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'hsncode' => $this->hsncode,
            'description' => $this->description,
            'tags' => $this->tags === null || $this->tags === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $this->tags)))),
            'thumbnail_img' => $this->thumbnail_img,
            'photos' => $this->photos ?? [],
            'unit' => $this->unit,
            'min_qty' => $this->min_qty,
            'piece_per_carton' => $this->piece_per_carton,
            'has_variants' => $this->variants->count() > 1,
            'in_stock' => $anyInStock,
            'option_groups' => $this->optionGroups(),
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'group' => $this->whenLoaded('categoryGroup', fn () => [
                'id' => $this->categoryGroup->id,
                'name' => $this->categoryGroup->name,
                'slug' => $this->categoryGroup->slug,
            ]),
            'taxes' => ProductTaxResource::collection($this->whenLoaded('taxes')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
