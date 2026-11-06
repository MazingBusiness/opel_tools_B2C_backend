<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Overrides\CatalogOverride;
use App\Modules\Catalog\Models\Overrides\CategoryGroupOverride;
use App\Modules\Catalog\Models\Overrides\CategoryOverride;
use App\Modules\Catalog\Models\Overrides\ProductOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Catalog\Support\CatalogPricing;
use App\Modules\Seo\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Admin catalog overrides: field registry, validation and version-guarded
 * writes. B2B rows are never written here.
 */
class CatalogOverrideService
{
    public const ENTITIES = ['groups', 'categories', 'products', 'variants'];

    /** Never overridable (identity / structure / URL). */
    public const LOCKED = [
        'id', 'slug', 'part_no', 'part_no_normalized', 'product_id', 'group_id', 'category_id',
        'category_group_id', 'parent_id', 'brand_id', 'options', 'is_default', 'position',
        'published', 'approved', 'is_active', 'synced_at', 'created_at', 'updated_at',
    ];

    /**
     * Overridable fields per entity => kind.
     * kinds: string | text | url | urls | int | qty | money | stock | bool | sort
     */
    public const FIELDS = [
        'groups' => ['name' => 'string', 'hidden' => 'bool', 'sort_order' => 'sort'],
        'categories' => ['name' => 'string', 'banner' => 'url', 'hidden' => 'bool', 'sort_order' => 'sort'],
        'products' => [
            'name' => 'string', 'description' => 'text', 'thumbnail_img' => 'url', 'photos' => 'urls',
            'tags' => 'string', 'hsncode' => 'string', 'unit' => 'string', 'min_qty' => 'qty',
            'piece_per_carton' => 'qty', 'hidden' => 'bool', 'sort_order' => 'sort',
        ],
        'variants' => [
            'label' => 'string', 'thumbnail_img' => 'url', 'unit_price' => 'money', 'list_price' => 'money',
            'mrp' => 'money', 'carton_price' => 'money', 'current_stock' => 'stock', 'min_qty' => 'qty',
            'hsncode' => 'string',
        ],
    ];

    /**
     * @return array{model: class-string<Model>, override: class-string<CatalogOverride>, seo: ?string}
     */
    public function config(string $entity): array
    {
        return match ($entity) {
            'groups' => ['model' => CategoryGroup::class, 'override' => CategoryGroupOverride::class, 'seo' => 'category_group'],
            'categories' => ['model' => Category::class, 'override' => CategoryOverride::class, 'seo' => 'category'],
            'products' => ['model' => Product::class, 'override' => ProductOverride::class, 'seo' => 'product'],
            'variants' => ['model' => ProductVariant::class, 'override' => ProductVariantOverride::class, 'seo' => null],
            default => abort(404, 'Unknown catalog entity.'),
        };
    }

    public function find(string $entity, int $id): Model
    {
        $class = $this->config($entity)['model'];
        $model = $class::query()->with('catalogOverride')->find($id);
        if ($model === null) {
            abort(404, 'Catalog item not found.');
        }

        return $model;
    }

    /**
     * Validate + normalise an override patch. Keys present are written; null resets that field.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function normalise(string $entity, Model $model, array $fields): array
    {
        $registry = self::FIELDS[$entity];
        $errors = [];
        $rules = [];

        foreach (array_keys($fields) as $field) {
            if (! is_string($field)) {
                continue;
            }
            if (in_array($field, self::LOCKED, true)) {
                $errors["fields.$field"] = "$field is a B2B identifier / structural field and cannot be overridden.";

                continue;
            }
            if (! array_key_exists($field, $registry)) {
                $errors["fields.$field"] = "Unknown field $field.";

                continue;
            }
            $rules["fields.$field"] = $this->rulesFor($registry[$field]);
            if ($registry[$field] === 'urls') {
                $rules["fields.$field.*"] = ['string', 'max:2048', 'url:http,https'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        Validator::make(['fields' => $fields], $rules)->validate();

        $out = [];
        foreach ($fields as $field => $value) {
            $kind = $registry[$field];
            if ($value === null) {
                $out[$field] = null;

                continue;
            }

            $out[$field] = match ($kind) {
                'string', 'url' => trim((string) $value),
                'text' => HtmlSanitizer::clean((string) $value) ?? '',
                'urls' => array_values(array_map(fn ($u): string => trim((string) $u), (array) $value)),
                'int', 'qty', 'sort', 'stock' => (int) $value,
                'money' => number_format((float) $value, 2, '.', ''),
                'bool' => (bool) $value,
            };

            if ($kind === 'stock') {
                $b2bStock = (int) $model->b2bValue($field);
                if ($out[$field] > $b2bStock) {
                    throw ValidationException::withMessages([
                        "fields.$field" => "Stock can only be lowered: B2B stock is $b2bStock. Use 0 to force out of stock.",
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function rulesFor(string $kind): array
    {
        return match ($kind) {
            'string' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'string', 'max:2048', function (string $attr, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && ! preg_match('#^https?://#i', trim($value))) {
                    $fail('Must be an absolute http(s) URL, or empty.');
                }
            }],
            'urls' => ['nullable', 'array', 'max:20'],
            'int' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sort' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'stock' => ['nullable', 'integer', 'min:0'],
            // Price overrides must be positive (null = no override).
            'money' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'bool' => ['nullable', 'boolean'],
        };
    }

    /**
     * Variant price sanity on the EFFECTIVE values after applying $values
     * (patch or reset; null = back to B2B): unit_price must not exceed mrp or
     * list_price. A side that is null is skipped. Only checked when the change
     * touches a price field, so pre-existing B2B data never blocks e.g. a label edit.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertPriceOrder(string $entity, Model $model, array $values): void
    {
        $prices = ['unit_price', 'mrp', 'list_price'];
        if ($entity !== 'variants' || array_intersect(array_keys($values), $prices) === []) {
            return;
        }

        $effective = [];
        foreach ($prices as $field) {
            $override = array_key_exists($field, $values) ? $values[$field] : $model->overrideValue($field);
            $b2b = $model->b2bValue($field);
            $effective[$field] = $field === 'unit_price'
                ? CatalogPricing::effective($b2b === null ? null : (string) $b2b, $override === null ? null : (string) $override)
                : ($override ?? $b2b);
        }

        $unit = $effective['unit_price'];
        if ($unit === null) {
            return;
        }

        foreach (['mrp' => 'MRP', 'list_price' => 'list price'] as $field => $label) {
            if ($effective[$field] !== null && (float) $unit > (float) $effective[$field]) {
                throw ValidationException::withMessages([
                    'fields.unit_price' => sprintf(
                        'Selling price %s would be above the %s %s (effective values after this change).',
                        number_format((float) $unit, 2, '.', ''), $label, number_format((float) $effective[$field], 2, '.', ''),
                    ),
                ]);
            }
        }
    }

    /**
     * Version-guarded write (same contract as admin SEO). Returns false on conflict.
     * expected 0 = no override row yet → INSERT (unique fk rejects a concurrent create).
     * expected N → UPDATE … WHERE fk = ? AND version = N (0 rows → conflict).
     *
     * @param  array<string, mixed>  $values
     */
    public function write(string $entity, int $id, int $expected, array $values, ?int $userId): bool
    {
        $class = $this->config($entity)['override'];
        $fk = $class::foreignKeyName();

        if ($expected === 0) {
            if ($class::query()->where($fk, $id)->exists()) {
                return false;
            }
            if ($values === [] || array_filter($values, fn ($v) => $v !== null) === []) {
                // Nothing to store (e.g. reset with no row): no-op, not a conflict.
                return true;
            }
            try {
                $row = new $class;
                $row->forceFill([$fk => $id, 'version' => 1, 'updated_by' => $userId] + $values);
                $row->saveQuietly();
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        } else {
            $columns = (new $class)->forceFill($values)->getAttributes();
            $affected = $class::query()
                ->where($fk, $id)
                ->where('version', $expected)
                ->update($columns + ['version' => DB::raw('version + 1'), 'updated_by' => $userId]);

            if ($affected !== 1) {
                return false;
            }
        }

        // Clears the server-side catalog cache (hidden sets) and the SEO payload cache.
        CatalogCache::bump();

        return true;
    }

    public function version(Model $model): int
    {
        return (int) ($model->overrideRow()?->version ?? 0);
    }

    /**
     * Per-field B2B / override / effective view.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldRows(string $entity, Model $model): array
    {
        $rows = [];
        foreach (self::FIELDS[$entity] as $field => $kind) {
            $override = $model->overrideValue($field);
            [$b2b, $effective] = match ($field) {
                'hidden' => [false, $override === true],
                'sort_order' => [null, $override],
                default => [$model->b2bValue($field), $model->getAttributeValue($field)],
            };
            $rows[] = [
                'field' => $field,
                'kind' => $kind,
                'b2b' => $b2b,
                'override' => $override,
                'effective' => $effective,
                'overridden' => $override !== null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function overriddenFields(Model $model): array
    {
        return $model->overrideRow()?->overriddenFields() ?? [];
    }
}
