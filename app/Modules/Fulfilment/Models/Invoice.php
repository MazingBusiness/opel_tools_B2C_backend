<?php

namespace App\Modules\Fulfilment\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'intra_state' => 'boolean',
            'taxable_total' => 'decimal:2', 'cgst_total' => 'decimal:2', 'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2', 'round_off' => 'decimal:2', 'grand_total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function challans(): HasMany
    {
        return $this->hasMany(Challan::class);
    }
}
