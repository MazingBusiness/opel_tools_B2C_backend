<?php

namespace App\Modules\Fulfilment\Support;

use App\Modules\Order\Models\Order;

/** Challans combine into one invoice only for the same customer + same delivery address. */
final class AddressKey
{
    public static function of(Order $order): string
    {
        $norm = fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));

        return sha1(implode('|', [
            $order->user_id,
            $norm($order->shipping_name), $norm($order->shipping_phone), $norm($order->shipping_line1),
            $norm($order->shipping_line2), $norm($order->shipping_city), $norm($order->shipping_state),
            $norm($order->shipping_pincode),
        ]));
    }
}
