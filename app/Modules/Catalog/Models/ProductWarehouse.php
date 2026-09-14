<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\ProductWarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'warehouse_code', 'qty', 'price'])]
class ProductWarehouse extends Model
{
    /** @use HasFactory<ProductWarehouseFactory> */
    use HasFactory;

    protected static function newFactory(): ProductWarehouseFactory
    {
        return ProductWarehouseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
