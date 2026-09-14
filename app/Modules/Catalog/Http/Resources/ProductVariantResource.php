<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $thumbnail = $this->thumbnail_img ?: $this->product?->thumbnail_img;

        return [
            'id' => $this->id,
            'part_no' => $this->part_no,
            'label' => $this->label,
            'options' => empty($this->options) ? (object) [] : $this->options,
            'unit_price' => $this->unit_price,
            'list_price' => $this->list_price,
            'mrp' => $this->mrp,
            'carton_price' => $this->carton_price,
            'current_stock' => $this->current_stock,
            'in_stock' => $this->inStock(),
            'min_qty' => $this->min_qty,
            'hsncode' => $this->hsncode ?: $this->product?->hsncode,
            'thumbnail_img' => $thumbnail,
            'is_default' => $this->is_default,
            'godown' => $this->whenLoaded('warehouses', function () {
                return $this->warehouses->map(fn ($warehouse): array => [
                    'warehouse_code' => $warehouse->warehouse_code,
                    'qty' => $warehouse->qty,
                ])->values()->all();
            }),
        ];
    }
}
