<?php

namespace App\Modules\Catalog\Models\Overrides;

class CategoryGroupOverride extends CatalogOverride
{
    protected $table = 'category_group_overrides';

    public static function foreignKeyName(): string
    {
        return 'category_group_id';
    }

    public static function overrideColumns(): array
    {
        return ['name', 'hidden', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'category_group_id' => 'integer',
            'hidden' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
