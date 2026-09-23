<?php

namespace App\Modules\Order\Support;

use App\Modules\Order\Models\Order;
use Carbon\CarbonInterface;

final class OrderTimeline
{
    /**
     * @return list<array{key: string, label: string, at: string, done: bool, current: bool}>
     */
    public static function initialPending(): array
    {
        return [
            self::step('placed', 'Order placed', '', false, false),
            self::step('packed', 'Packed', '', false, false),
            self::step('shipped', 'Shipped', '', false, false),
            self::step('delivered', 'Delivered', '', false, false),
        ];
    }

    /**
     * @param  list<array{key?: string, at?: string}>|null  $existing
     * @return list<array{key: string, label: string, at: string, done: bool, current: bool}>
     */
    public static function forStatus(string $status, ?CarbonInterface $placedAt = null, ?array $existing = null): array
    {
        $placedLabel = $placedAt?->timezone(config('app.timezone'))->format('j M, g:i A') ?? '';
        $existing = is_array($existing) ? $existing : [];

        $at = static function (string $key) use ($existing, $placedLabel): string {
            foreach ($existing as $step) {
                if (($step['key'] ?? '') === $key && filled($step['at'] ?? null)) {
                    return (string) $step['at'];
                }
            }

            return $key === 'placed' ? $placedLabel : '';
        };

        return match ($status) {
            'pending_payment', 'failed', 'cancelled' => [
                self::step('placed', 'Order placed', $at('placed'), $status !== 'pending_payment', false),
                self::step('packed', 'Packed', '', false, false),
                self::step('shipped', 'Shipped', '', false, false),
                self::step('delivered', 'Delivered', '', false, false),
            ],
            'processing' => [
                self::step('placed', 'Order placed', $at('placed') ?: $placedLabel, true, false),
                self::step('packed', 'Packed', $at('packed'), false, true),
                self::step('shipped', 'Shipped', '', false, false),
                self::step('delivered', 'Delivered', '', false, false),
            ],
            'shipped' => [
                self::step('placed', 'Order placed', $at('placed') ?: $placedLabel, true, false),
                self::step('packed', 'Packed', $at('packed') ?: $placedLabel, true, false),
                self::step('shipped', 'Shipped', $at('shipped') ?: now()->format('j M, g:i A'), true, false),
                self::step('delivered', 'Delivered', '', false, true),
            ],
            'delivered' => [
                self::step('placed', 'Order placed', $at('placed') ?: $placedLabel, true, false),
                self::step('packed', 'Packed', $at('packed') ?: $placedLabel, true, false),
                self::step('shipped', 'Shipped', $at('shipped') ?: $placedLabel, true, false),
                self::step('delivered', 'Delivered', $at('delivered') ?: now()->format('j M, g:i A'), true, false),
            ],
            default => self::initialPending(),
        };
    }

    public static function applyPaid(Order $order, ?string $zohoPaymentId = null): void
    {
        $updates = [
            'payment_status' => 'paid',
            'paid_at' => $order->paid_at ?? now(),
        ];

        if (filled($zohoPaymentId)) {
            $updates['zoho_payment_id'] = $zohoPaymentId;
        }

        // Advance pending/legacy-paid/failed → processing once; re-polls stay idempotent.
        if (in_array($order->status, ['pending_payment', 'failed', 'paid'], true)) {
            $updates['status'] = 'processing';
            $updates['timeline'] = self::forStatus(
                'processing',
                $order->created_at,
                is_array($order->timeline) ? $order->timeline : null,
            );
        } elseif ($order->status === 'processing' && blank($order->timeline)) {
            $updates['timeline'] = self::forStatus('processing', $order->created_at, null);
        }

        $order->update($updates);
    }

    /**
     * @return array{key: string, label: string, at: string, done: bool, current: bool}
     */
    private static function step(string $key, string $label, string $at, bool $done, bool $current): array
    {
        return compact('key', 'label', 'at', 'done', 'current');
    }
}
