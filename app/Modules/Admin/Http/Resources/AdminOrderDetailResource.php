<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Http\Request;

/**
 * Order detail for admin: list fields + items, address, timeline, payment_notes, user.
 *
 * @mixin Order
 */
class AdminOrderDetailResource extends AdminOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timeline = is_array($this->timeline) && $this->timeline !== []
            ? $this->timeline
            : OrderTimeline::forStatus((string) $this->status, $this->created_at, null);

        return array_merge(parent::toArray($request), [
            'db_id' => $this->id,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'payment_notes' => $this->payment_notes,
            'zoho_payment_id' => $this->zoho_payment_id,
            'b2b_order_id' => $this->b2b_order_id,
            'handoff_status' => $this->handoff_status,
            'shipping_address' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'line1' => $this->shipping_line1,
                'line2' => $this->shipping_line2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'pincode' => $this->shipping_pincode,
            ],
            'timeline' => $timeline,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'title' => $item->title,
                'image_url' => $item->image_url,
                'unit_price' => (float) $item->unit_price,
                'qty' => (int) $item->qty,
                'line_total' => (float) $item->line_total,
            ])->values()->all()),
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
