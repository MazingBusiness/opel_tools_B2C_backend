<?php

namespace App\Modules\Catalog\Models\Concerns;

use App\Modules\Catalog\Models\Overrides\CatalogOverride;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Effective-value merge for B2B catalog rows (the single merge point).
 *
 * Reading an overridable attribute returns the admin override when one is set
 * (non-null), else the B2B value. Writes are untouched: stored attributes stay
 * the B2B base, so a save() can never persist an override into a B2B column.
 * Use b2bValue() for the raw B2B value.
 *
 * Using models must declare `protected $with = ['catalogOverride'];` and
 * implement catalogOverrideModel().
 */
trait HasCatalogOverride
{
    /**
     * @return class-string<CatalogOverride>
     */
    abstract public static function catalogOverrideModel(): string;

    public function catalogOverride(): HasOne
    {
        $class = static::catalogOverrideModel();

        return $this->hasOne($class, $class::foreignKeyName());
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (! in_array($key, $this->effectiveAttributeKeys(), true)) {
            return $value;
        }

        return $this->effectiveAttribute($key, $value);
    }

    /** Raw B2B value (casts applied, no override). */
    public function b2bValue(string $key): mixed
    {
        return parent::getAttributeValue($key);
    }

    /** Admin override for $key, or null when there is none. */
    public function overrideValue(string $key): mixed
    {
        return $this->overrideRow()?->getAttribute($key);
    }

    public function overrideRow(): ?CatalogOverride
    {
        if ($this->relationLoaded('catalogOverride')) {
            return $this->getRelation('catalogOverride');
        }

        if (! $this->exists || $this->getKey() === null) {
            return null;
        }

        return $this->getRelationValue('catalogOverride');
    }

    public function adminSortOrder(): ?int
    {
        $value = $this->overrideValue('sort_order');

        return $value === null ? null : (int) $value;
    }

    public function isHiddenByOverride(): bool
    {
        return $this->overrideValue('hidden') === true;
    }

    /**
     * Attributes whose reads go through effectiveAttribute().
     *
     * @return list<string>
     */
    protected function effectiveAttributeKeys(): array
    {
        $class = static::catalogOverrideModel();

        return array_values(array_diff($class::overrideColumns(), ['hidden', 'sort_order']));
    }

    protected function effectiveAttribute(string $key, mixed $b2b): mixed
    {
        $override = $this->overrideValue($key);

        return $override ?? $b2b;
    }
}
