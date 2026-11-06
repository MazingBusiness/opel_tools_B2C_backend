<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Models\Concerns\HasCatalogOverride;
use App\Modules\Catalog\Models\Overrides\ProductVariantOverride;
use App\Modules\Catalog\Support\CatalogPricing;
use App\Modules\Catalog\Support\PartNumber;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'part_no',
    'part_no_normalized',
    'options',
    'label',
    'thumbnail_img',
    'unit_price',
    'list_price',
    'mrp',
    'carton_price',
    'current_stock',
    'min_qty',
    'hsncode',
    'is_default',
    'position',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;
    use HasCatalogOverride;

    /** @var list<string> */
    protected $with = ['catalogOverride'];

    public static function catalogOverrideModel(): string
    {
        return ProductVariantOverride::class;
    }

    protected function effectiveAttribute(string $key, mixed $b2b): mixed
    {
        $override = $this->overrideValue($key);

        return match ($key) {
            'unit_price' => CatalogPricing::effective($b2b === null ? null : (string) $b2b, $override === null ? null : (string) $override),
            // Stock override is a cap: it can lower availability, never raise it.
            'current_stock' => $override === null ? $b2b : min((int) $b2b, (int) $override),
            default => $override ?? $b2b,
        };
    }

    /** Effective unit price used by storefront, cart and checkout. */
    public function effectiveUnitPrice(): float
    {
        return (float) $this->unit_price;
    }

    /** Effective stock (B2B stock capped by any admin override). */
    public function effectiveStock(): int
    {
        return (int) $this->current_stock;
    }

    /**
     * SQL twin of the effective unit price for a product_variants row.
     * Keep in sync with CatalogPricing (identity logic today).
     */
    public static function effectivePriceSql(string $table = 'product_variants'): string
    {
        return "coalesce((select pvo.unit_price from product_variant_overrides pvo where pvo.product_variant_id = {$table}.id), {$table}.unit_price)";
    }

    /** SQL twin of the effective stock: min(B2B stock, override cap). */
    public static function effectiveStockSql(string $table = 'product_variants'): string
    {
        $cap = "(select pvo.current_stock from product_variant_overrides pvo where pvo.product_variant_id = {$table}.id)";

        return "(case when {$cap} is not null and {$cap} < {$table}.current_stock then {$cap} else {$table}.current_stock end)";
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $variant->part_no_normalized = PartNumber::normalize($variant->part_no);
        });

        static::saved(function (ProductVariant $variant): void {
            if (! $variant->is_default) {
                return;
            }

            static::query()
                ->where('product_id', $variant->product_id)
                ->where('id', '!=', $variant->id)
                ->update(['is_default' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'carton_price' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function inStock(): bool
    {
        return $this->effectiveStock() > 0;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductWarehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(ProductWarehouse::class);
    }
}
