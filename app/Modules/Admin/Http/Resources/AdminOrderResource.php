<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Order\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order row for the admin Orders list.
 *
 * @mixin Order
 */
class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'payment_method' => $this->payment_method ?: 'zoho',
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'item_count' => (int) $this->item_count,
            'grand_total' => (float) $this->grand_total,
            'shipping_name' => $this->shipping_name,
            'shipping_phone' => $this->shipping_phone,
            'placed_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
