<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'rate'])]
class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use HasFactory;

    protected static function newFactory(): TaxFactory
    {
        return TaxFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ProductTax, $this>
     */
    public function productTaxes(): HasMany
    {
        return $this->hasMany(ProductTax::class);
    }
}
