<?php

namespace App\Modules\Cart\Http\Resources;

use App\Modules\Catalog\Http\Resources\BrandResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Live-joined catalog fields (not snapshotted at add-time).
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $variant = $this->variant;

        $available = $product !== null
            && (bool) $product->published
            && (bool) $product->approved
            && $variant !== null;

        $unitPrice = $available ? (float) $variant->unit_price : 0.0;
        $mrp = $available ? (float) ($variant->mrp ?? $variant->list_price ?? $variant->unit_price) : 0.0;
        $listPrice = $available ? (float) ($variant->list_price ?? $variant->unit_price) : 0.0;
        $stock = $available ? (int) $variant->current_stock : 0;
        $minQty = $available
            ? max(1, (int) ($variant->min_qty ?? $product->min_qty ?? 1))
            : 1;
        $qty = (int) $this->qty;
        $inStock = $available && $stock > 0;
        $qtyClamped = $available ? min($qty, max(0, $stock)) : 0;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'qty' => $qty,
            'added_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'available' => $available,
            'in_stock' => $inStock,
            'min_qty' => $minQty,
            'max_qty' => $available ? min(99, max(0, $stock)) : 0,
            'line_total' => round($unitPrice * $qtyClamped, 2),
            'product' => $available ? [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'thumbnail_img' => $variant->thumbnail_img ?: $product->thumbnail_img,
                'brand' => $product->relationLoaded('brand')
                    ? new BrandResource($product->brand)
                    : null,
            ] : null,
            'variant' => $available ? [
                'id' => $variant->id,
                'part_no' => $variant->part_no,
                'label' => $variant->label,
                'unit_price' => $variant->unit_price,
                'list_price' => $listPrice,
                'mrp' => $mrp,
                'current_stock' => $stock,
                'min_qty' => $minQty,
            ] : null,
            // Hint for FE if stored qty exceeds live stock (does not auto-mutate).
            'qty_exceeds_stock' => $available && $qty > $stock,
            'effective_qty' => $qtyClamped,
        ];
    }
}
