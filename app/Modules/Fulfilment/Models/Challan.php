<?php

namespace App\Modules\Fulfilment\Models;

use App\Modules\Order\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challan extends Model
{
    protected $fillable = ['number', 'order_id', 'sub_order_id', 'branch', 'address_key', 'invoice_id', 'created_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChallanItem::class);
    }
}
