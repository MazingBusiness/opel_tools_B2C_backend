<?php

namespace App\Modules\Fulfilment\Models;

use App\Modules\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubOrderItem extends Model
{
    protected $fillable = ['sub_order_id', 'order_item_id', 'part_no', 'qty', 'qty_challaned', 'qty_cancelled'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'qty_challaned' => 'integer', 'qty_cancelled' => 'integer'];
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** Allocated but not yet on a challan or cancelled. */
    public function pendingQty(): int
    {
        return max(0, $this->qty - $this->qty_challaned - $this->qty_cancelled);
    }
}
