<?php

namespace App\Modules\Catalog\Models\Overrides;

class ProductVariantOverride extends CatalogOverride
{
    protected $table = 'product_variant_overrides';

    public static function foreignKeyName(): string
    {
        return 'product_variant_id';
    }

    public static function overrideColumns(): array
    {
        return [
            'label', 'thumbnail_img', 'unit_price', 'list_price', 'mrp', 'carton_price',
            'current_stock', 'min_qty', 'hsncode',
        ];
    }

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'integer',
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'carton_price' => 'decimal:2',
            'current_stock' => 'integer',
            'min_qty' => 'integer',
            'version' => 'integer',
        ];
    }
}
