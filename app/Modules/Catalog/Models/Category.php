<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Models\Concerns\HasCatalogOverride;
use App\Modules\Catalog\Models\Overrides\CategoryOverride;
use App\Modules\Catalog\Support\CatalogVisibility;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'parent_id', 'category_group_id', 'banner', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;
    use HasCatalogOverride;

    /** @var list<string> */
    protected $with = ['catalogOverride'];

    public static function catalogOverrideModel(): string
    {
        return CategoryOverride::class;
    }

    protected function effectiveAttributeKeys(): array
    {
        return ['name', 'banner', 'is_active'];
    }

    protected function effectiveAttribute(string $key, mixed $b2b): mixed
    {
        if ($key === 'is_active') {
            // Hidden itself, via a hidden parent, or via a hidden group.
            return (bool) $b2b && ! CatalogVisibility::categoryHidden($this->getKey() === null ? null : (int) $this->getKey());
        }

        return $this->overrideValue($key) ?? $b2b;
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeVisible(Builder $query): Builder
    {
        $hidden = CatalogVisibility::hiddenCategoryIds();

        return $query->where($query->qualifyColumn('is_active'), true)
            ->when($hidden !== [], fn (Builder $q) => $q->whereNotIn($q->qualifyColumn('id'), $hidden));
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CategoryGroup, $this>
     */
    public function categoryGroup(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
