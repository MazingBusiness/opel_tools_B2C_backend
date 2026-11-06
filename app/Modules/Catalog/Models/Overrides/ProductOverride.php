<?php

namespace App\Modules\Catalog\Models\Overrides;

class ProductOverride extends CatalogOverride
{
    protected $table = 'product_overrides';

    public static function foreignKeyName(): string
    {
        return 'product_id';
    }

    public static function overrideColumns(): array
    {
        return [
            'name', 'description', 'thumbnail_img', 'photos', 'tags', 'hsncode',
            'unit', 'min_qty', 'piece_per_carton', 'hidden', 'sort_order',
        ];
    }

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'photos' => 'array',
            'min_qty' => 'integer',
            'piece_per_carton' => 'integer',
            'hidden' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
