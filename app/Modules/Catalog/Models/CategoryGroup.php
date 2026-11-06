<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Models\Concerns\HasCatalogOverride;
use App\Modules\Catalog\Models\Overrides\CategoryGroupOverride;
use App\Modules\Catalog\Support\CatalogVisibility;
use Database\Factories\CategoryGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'is_active'])]
class CategoryGroup extends Model
{
    /** @use HasFactory<CategoryGroupFactory> */
    use HasFactory;
    use HasCatalogOverride;

    /** @var list<string> */
    protected $with = ['catalogOverride'];

    public static function catalogOverrideModel(): string
    {
        return CategoryGroupOverride::class;
    }

    protected function effectiveAttributeKeys(): array
    {
        return ['name', 'is_active'];
    }

    protected function effectiveAttribute(string $key, mixed $b2b): mixed
    {
        if ($key === 'is_active') {
            // Admin can only hide: effective active = B2B active AND not hidden.
            return (bool) $b2b && ! $this->isHiddenByOverride();
        }

        return $this->overrideValue($key) ?? $b2b;
    }

    /**
     * Storefront-visible: B2B active and not hidden by an admin override.
     *
     * @param  Builder<CategoryGroup>  $query
     * @return Builder<CategoryGroup>
     */
    public function scopeVisible(Builder $query): Builder
    {
        $hidden = CatalogVisibility::hiddenGroupIds();

        return $query->where($query->qualifyColumn('is_active'), true)
            ->when($hidden !== [], fn (Builder $q) => $q->whereNotIn($q->qualifyColumn('id'), $hidden));
    }

    protected static function newFactory(): CategoryGroupFactory
    {
        return CategoryGroupFactory::new();
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
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'group_id');
    }
}
