<?php

namespace App\Modules\Order\Http\Resources;

use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Order\Models\Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timeline = is_array($this->timeline) && $this->timeline !== []
            ? $this->timeline
            : OrderTimeline::forStatus((string) $this->status, $this->created_at, null);

        return [
            // FE track/profile historically used OPL-* as id
            'id' => $this->number,
            'db_id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'item_count' => $this->item_count,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'grand_total' => (float) $this->grand_total,
            'payment_link_url' => $this->when(
                $this->payment_status !== 'paid',
                $this->payment_link_url,
            ),
            'payment_link_expires_at' => $this->payment_link_expires_at?->toDateString(),
            'zoho_payment_id' => $this->zoho_payment_id,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'placed_at' => $this->created_at?->toIso8601String(),
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
                'imageUrl' => $item->image_url,
                'unit_price' => (float) $item->unit_price,
                'unitPrice' => (float) $item->unit_price,
                'qty' => $item->qty,
                'line_total' => (float) $item->line_total,
                'href' => '/products/'.$item->product_id,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
