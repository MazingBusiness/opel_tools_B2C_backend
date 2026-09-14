<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\PartNumber;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'part_no',
    'part_no_normalized',
    'options',
    'label',
    'thumbnail_img',
    'unit_price',
    'list_price',
    'mrp',
    'carton_price',
    'current_stock',
    'min_qty',
    'hsncode',
    'is_default',
    'position',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $variant->part_no_normalized = PartNumber::normalize($variant->part_no);
        });

        static::saved(function (ProductVariant $variant): void {
            if (! $variant->is_default) {
                return;
            }

            static::query()
                ->where('product_id', $variant->product_id)
                ->where('id', '!=', $variant->id)
                ->update(['is_default' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'carton_price' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function inStock(): bool
    {
        return $this->current_stock > 0;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductWarehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(ProductWarehouse::class);
    }
}
