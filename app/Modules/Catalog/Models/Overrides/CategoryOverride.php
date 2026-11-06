<?php

namespace App\Modules\Catalog\Models\Overrides;

class CategoryOverride extends CatalogOverride
{
    protected $table = 'category_overrides';

    public static function foreignKeyName(): string
    {
        return 'category_id';
    }

    public static function overrideColumns(): array
    {
        return ['name', 'banner', 'hidden', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'hidden' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
