<?php

namespace App\Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Polymorphic-by-key SEO overrides: (target_type, target_key) is unique.
 * target_type: page | product | category_group | category.
 */
class SeoMeta extends Model
{
    public const TYPE_PAGE = 'page';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_CATEGORY_GROUP = 'category_group';

    public const TYPE_CATEGORY = 'category';

    public const TYPES = [
        self::TYPE_PAGE,
        self::TYPE_PRODUCT,
        self::TYPE_CATEGORY_GROUP,
        self::TYPE_CATEGORY,
    ];

    protected $table = 'seo_meta';

    protected $fillable = [
        'target_type',
        'target_key',
        'version',
        'seo_title',
        'meta_description',
        'h1',
        'content',
        'primary_keyword',
        'secondary_keywords',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image',
        'schema_jsonld',
        'faq',
        'related_product_ids',
        'image_alts',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'secondary_keywords' => 'array',
            'faq' => 'array',
            'related_product_ids' => 'array',
            'image_alts' => 'array',
        ];
    }

    public static function for(string $type, string|int $key): ?self
    {
        return static::query()
            ->where('target_type', $type)
            ->where('target_key', (string) $key)
            ->first();
    }
}
