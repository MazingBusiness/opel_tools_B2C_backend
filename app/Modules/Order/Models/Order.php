<?php

namespace App\Modules\Order\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'number',
        'status',
        'address_id',
        'shipping_name',
        'shipping_phone',
        'shipping_line1',
        'shipping_line2',
        'shipping_city',
        'shipping_state',
        'shipping_pincode',
        'item_count',
        'subtotal',
        'shipping_fee',
        'grand_total',
        'currency',
        'payment_status',
        'payment_link_id',
        'payment_link_url',
        'payment_link_expires_at',
        'zoho_payment_id',
        'paid_at',
        'b2b_order_id',
        'handoff_status',
        'timeline',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'payment_link_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'timeline' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
