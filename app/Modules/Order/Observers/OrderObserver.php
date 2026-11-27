<?php

namespace App\Modules\Order\Observers;

use App\Modules\Order\Jobs\SendOrderEmail;
use App\Modules\Order\Mail\OrderMail;
use App\Modules\Order\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Turns order state changes into customer emails. Every path that moves an
 * order (placement, payment, approval, fulfilment) goes through the model, so
 * this is the one place that decides which email an order gets.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        if ($order->status === 'processing') {
            $this->queue($order, [OrderMail::PLACED]);
        }
    }

    public function updated(Order $order): void
    {
        $events = [];

        if ($order->wasChanged('status')) {
            $events[] = match ($order->status) {
                'processing' => OrderMail::PLACED,
                'shipped' => OrderMail::SHIPPED,
                'delivered' => OrderMail::DELIVERED,
                default => null,
            };
        }

        if ($order->wasChanged('approval_status')) {
            $events[] = match ($order->approval_status) {
                'approved' => OrderMail::APPROVED,
                'cancelled' => OrderMail::REJECTED,
                default => null,
            };
        }

        $this->queue($order, array_values(array_filter($events)));
    }

    /** @param  list<string>  $events */
    private function queue(Order $order, array $events): void
    {
        if (in_array(OrderMail::PLACED, $events, true) && SendOrderEmail::staffRecipients() !== []) {
            $events[] = OrderMail::STAFF_NEW_ORDER;
        }

        foreach ($events as $event) {
            // The insert joins the caller's transaction: a rollback drops the
            // claim and the queued job together.
            $claimed = DB::table('order_notifications')->insertOrIgnore([
                'order_id' => $order->id,
                'event' => $event,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($claimed !== 1) {
                continue;
            }

            $id = (int) DB::table('order_notifications')
                ->where('order_id', $order->id)->where('event', $event)->value('id');

            SendOrderEmail::dispatch($id)->afterCommit();
        }
    }
}
