<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'part_no',
        'title',
        'image_url',
        'unit_price',
        'gst_rate',
        'hsn',
        'qty',
        'qty_cancelled',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'qty' => 'integer',
            'qty_cancelled' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
