<?php

namespace App\Modules\Catalog\Models\Overrides;

use App\Modules\Catalog\Support\CatalogCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the per-entity override rows. NULL column = no override.
 * Admin saves use a raw versioned UPDATE (no model events) and bump the
 * cache themselves; these hooks cover direct model writes (tests, tinker).
 */
abstract class CatalogOverride extends Model
{
    protected $guarded = ['id'];

    /** Column holding the B2B row id (unique). */
    abstract public static function foreignKeyName(): string;

    /**
     * Override columns (everything except id / fk / version / metadata).
     *
     * @return list<string>
     */
    abstract public static function overrideColumns(): array;

    protected static function booted(): void
    {
        static::saved(fn () => CatalogCache::bump());
        static::deleted(fn () => CatalogCache::bump());
    }

    public function hasAnyOverride(): bool
    {
        foreach (static::overrideColumns() as $column) {
            if ($this->getAttribute($column) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function overriddenFields(): array
    {
        return array_values(array_filter(
            static::overrideColumns(),
            fn (string $column): bool => $this->getAttribute($column) !== null,
        ));
    }
}
