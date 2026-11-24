<?php

namespace App\Modules\Fulfilment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChallanItem extends Model
{
    protected $fillable = ['challan_id', 'sub_order_item_id', 'order_item_id', 'part_no', 'title', 'unit_price', 'gst_rate', 'hsn', 'qty'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'gst_rate' => 'decimal:2', 'qty' => 'integer'];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    public function b2bPush(): HasOne
    {
        return $this->hasOne(B2bPush::class);
    }
}
