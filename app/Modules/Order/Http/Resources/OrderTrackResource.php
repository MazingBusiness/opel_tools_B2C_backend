<?php

namespace App\Modules\Order\Http\Resources;

use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Order\Models\Order */
class OrderTrackResource extends JsonResource
{
    /**
     * Public tracking DTO — no full street address or raw phone.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timeline = is_array($this->timeline) && $this->timeline !== []
            ? $this->timeline
            : OrderTimeline::forStatus((string) $this->status, $this->created_at, null);

        $phone = (string) $this->shipping_phone;
        $maskedPhone = strlen($phone) >= 4
            ? str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4)
            : '****';

        return [
            'id' => $this->number,
            'number' => $this->number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'placed_at' => $this->created_at?->toIso8601String(),
            'item_count' => $this->item_count,
            'shipping_address' => [
                'name' => $this->shipping_name,
                'phone' => $maskedPhone,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'pincode' => $this->shipping_pincode,
            ],
            'timeline' => $timeline,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'title' => $item->title,
                'image_url' => $item->image_url,
                'qty' => $item->qty,
            ])),
        ];
    }
}
