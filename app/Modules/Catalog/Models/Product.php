<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'slug',
    'hsncode',
    'group_id',
    'category_id',
    'brand_id',
    'thumbnail_img',
    'photos',
    'description',
    'tags',
    'published',
    'approved',
    'unit',
    'min_qty',
    'piece_per_carton',
    'synced_at',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'published' => 'boolean',
            'approved' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $query = static::query()->published();

        if ($field !== null) {
            return $query->where($field, $value)->first();
        }

        if (ctype_digit((string) $value)) {
            return $query->whereKey($value)->first();
        }

        return $query->where('slug', $value)->first();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true)->where('approved', true);
    }

    /**
     * @return BelongsTo<CategoryGroup, $this>
     */
    public function categoryGroup(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * @return HasMany<ProductTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(ProductTax::class);
    }

    /**
     * @return list<array{name: string, values: list<string>}>
     */
    public function optionGroups(): array
    {
        $groups = [];

        foreach ($this->variants as $variant) {
            foreach ($variant->options ?? [] as $name => $value) {
                $groups[$name][] = (string) $value;
            }
        }

        return collect($groups)
            ->map(fn (array $values, string $name): array => [
                'name' => $name,
                'values' => array_values(array_unique($values)),
            ])
            ->values()
            ->all();
    }
}
