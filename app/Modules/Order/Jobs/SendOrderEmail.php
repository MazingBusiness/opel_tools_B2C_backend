<?php

namespace App\Modules\Order\Jobs;

use App\Modules\Order\Mail\OrderMail;
use App\Modules\Order\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendOrderEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $notificationId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $row = DB::table('order_notifications')->find($this->notificationId);
        if ($row === null || $row->sent_at !== null) {
            return;
        }

        $order = Order::query()->with(['items', 'user'])->find($row->order_id);
        $to = $order === null ? [] : $this->recipients($order, $row->event);
        if ($to === []) {
            DB::table('order_notifications')->where('id', $row->id)
                ->update(['error' => 'No recipient email.', 'updated_at' => now()]);

            return;
        }

        Mail::to($to)->send(new OrderMail($order, $row->event));

        DB::table('order_notifications')->where('id', $row->id)->update([
            'recipient' => Str::limit(implode(',', $to), 250, ''),
            'sent_at' => now(),
            'failed_at' => null,
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        DB::table('order_notifications')->where('id', $this->notificationId)->update([
            'failed_at' => now(),
            'error' => Str::limit($e->getMessage(), 1000),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function recipients(Order $order, string $event): array
    {
        if ($event === OrderMail::STAFF_NEW_ORDER) {
            return self::staffRecipients();
        }

        $email = trim((string) $order->user?->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email] : [];
    }

    /** @return list<string> */
    public static function staffRecipients(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) config('mail.order_alert_to'))),
            fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
