<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductTax;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductTax
 */
class ProductTaxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->tax_id,
            'name' => $this->taxRate?->name,
            'rate' => $this->tax,
            'tax_type' => $this->tax_type,
        ];
    }
}
