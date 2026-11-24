<?php

namespace App\Modules\Fulfilment\Models;

use App\Modules\Order\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubOrder extends Model
{
    protected $fillable = ['order_id', 'branch', 'number', 'approved_by', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubOrderItem::class);
    }

    public function challans(): HasMany
    {
        return $this->hasMany(Challan::class);
    }
}
