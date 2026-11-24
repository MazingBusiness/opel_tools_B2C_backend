<?php

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Models\SubOrder;
use App\Modules\Fulfilment\Support\Branches;
use App\Modules\Fulfilment\Support\DocumentNumber;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Order approval: split unallocated quantity into one sub-order per branch,
 * or cancel it. Stock is advisory only; the hard limit is the ordered qty.
 */
class OrderAllocator
{
    /** Confirmed = COD placed, or online payment captured. */
    public static function blockedReason(Order $order): ?string
    {
        if ($order->status !== 'processing') {
            return "Only processing orders can be approved (this one is {$order->status}).";
        }
        if ($order->payment_method !== 'cod' && $order->payment_status !== 'paid') {
            return 'Online payment is not captured yet.';
        }

        return null;
    }

    /** Why the order cannot leave processing yet (approval or sub-orders still open), or null. */
    public static function dispatchBlockedReason(Order $order): ?string
    {
        if (in_array($order->approval_status, [null, 'partial'], true)) {
            return 'Approve and allocate every line before marking this order shipped.';
        }
        $open = DB::table('sub_order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'sub_order_items.sub_order_id')
            ->where('sub_orders.order_id', $order->id)
            ->whereRaw('sub_order_items.qty > sub_order_items.qty_challaned + sub_order_items.qty_cancelled')
            ->exists();

        return $open ? 'Sub-orders still have quantity waiting for a challan.' : null;
    }

    /** @return array<int, int> order_item_id => allocated qty, net of sub-order cancellations */
    public static function allocatedByItem(Order $order): array
    {
        return DB::table('sub_order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'sub_order_items.sub_order_id')
            ->where('sub_orders.order_id', $order->id)
            ->groupBy('sub_order_items.order_item_id')
            ->selectRaw('sub_order_items.order_item_id as id, SUM(sub_order_items.qty - sub_order_items.qty_cancelled) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id => (int) $r->qty])
            ->all();
    }

    public static function unallocated(OrderItem $item, array $allocated): int
    {
        return max(0, $item->qty - $item->qty_cancelled - ($allocated[$item->id] ?? 0));
    }

    /**
     * @param  array<int, array<string, int>>  $plan  order_item_id => [BRANCH => qty]
     * @return Collection<int, SubOrder>
     */
    public function allocate(string $number, int $expectedVersion, array $plan, ?int $adminId): Collection
    {
        return DB::transaction(function () use ($number, $expectedVersion, $plan, $adminId) {
            [$order, $items, $allocated] = $this->lockAndCheck($number, $expectedVersion);

            $byBranch = [];
            foreach ($plan as $itemId => $branches) {
                $item = $items->get($itemId) ?? abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Line {$itemId} is not on this order.");
                $sum = array_sum($branches);
                if ($sum === 0) {
                    continue;
                }
                if (blank($item->part_no)) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, "\"{$item->title}\" has no part number and cannot be allocated.");
                }
                $left = self::unallocated($item, $allocated);
                if ($sum > $left) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, "\"{$item->title}\": allocating {$sum} but only {$left} is unallocated.");
                }
                foreach ($branches as $branch => $qty) {
                    if ($qty > 0) {
                        $byBranch[$branch][] = [$item, $qty];
                    }
                }
            }
            if ($byBranch === []) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Allocate at least one unit.');
            }

            $created = collect();
            foreach (Branches::codes() as $branch) {
                if (! isset($byBranch[$branch])) {
                    continue;
                }
                $sub = SubOrder::query()->create([
                    'order_id' => $order->id,
                    'branch' => $branch,
                    'number' => DocumentNumber::subOrder($branch, DocumentNumber::now()),
                    'approved_by' => $adminId,
                ]);
                foreach ($byBranch[$branch] as [$item, $qty]) {
                    $sub->items()->create([
                        'order_item_id' => $item->id,
                        'part_no' => $item->part_no,
                        'qty' => $qty,
                    ]);
                }
                $created->push($sub);
            }

            $this->finish($order);

            return $created;
        });
    }

    /** @param  array<int, int>  $cancel  order_item_id => qty */
    public function cancelUnallocated(string $number, int $expectedVersion, array $cancel): void
    {
        DB::transaction(function () use ($number, $expectedVersion, $cancel) {
            [$order, $items, $allocated] = $this->lockAndCheck($number, $expectedVersion);

            $any = false;
            foreach ($cancel as $itemId => $qty) {
                if ($qty === 0) {
                    continue;
                }
                $item = $items->get($itemId) ?? abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Line {$itemId} is not on this order.");
                $left = self::unallocated($item, $allocated);
                if ($qty > $left) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, "\"{$item->title}\": cancelling {$qty} but only {$left} is unallocated.");
                }
                $item->update(['qty_cancelled' => $item->qty_cancelled + $qty]);
                $any = true;
            }
            if (! $any) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Cancel at least one unit.');
            }

            $this->finish($order);
        });
    }

    /** @return array{0: Order, 1: Collection<int, OrderItem>, 2: array<int, int>} */
    private function lockAndCheck(string $number, int $expectedVersion): array
    {
        $order = Order::query()->where('number', $number)->lockForUpdate()->first()
            ?? abort(Response::HTTP_NOT_FOUND, 'Order not found.');

        if ($reason = self::blockedReason($order)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $reason);
        }
        if ($order->allocation_version !== $expectedVersion) {
            abort(Response::HTTP_CONFLICT, 'This order was changed by someone else. Reload and try again.');
        }

        $items = $order->items()->lockForUpdate()->get()->keyBy('id');

        return [$order, $items, self::allocatedByItem($order)];
    }

    public function finish(Order $order): void
    {
        $items = $order->items()->get();
        $allocated = self::allocatedByItem($order);
        $left = $items->sum(fn (OrderItem $i) => self::unallocated($i, $allocated));
        $anyAllocated = array_sum($allocated) > 0;

        $status = match (true) {
            $left > 0 => 'partial',
            $anyAllocated => 'approved',
            default => 'cancelled',
        };

        $order->update([
            'approval_status' => $status,
            'allocation_version' => $order->allocation_version + 1,
        ]);
    }
}
