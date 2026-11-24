<?php

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Jobs\PushChallanItemToB2b;
use App\Modules\Fulfilment\Models\B2bPush;
use App\Modules\Fulfilment\Models\Challan;
use App\Modules\Fulfilment\Models\SubOrder;
use App\Modules\Fulfilment\Models\SubOrderItem;
use App\Modules\Fulfilment\Support\AddressKey;
use App\Modules\Fulfilment\Support\DocumentNumber;
use App\Modules\Fulfilment\Support\TaxSnapshot;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sub-order actions. Lock order: order row, then sub-order row (same as approval).
 * Quantity already on a challan can never be cancelled.
 */
class SubOrderService
{
    public function __construct(private readonly OrderAllocator $allocator) {}

    /** @param  array<int, int>  $qtyByItem  sub_order_item_id => qty */
    public function createChallan(int $subOrderId, int $expectedVersion, array $qtyByItem, ?int $adminId): Challan
    {
        $challan = DB::transaction(function () use ($subOrderId, $expectedVersion, $qtyByItem, $adminId) {
            [$order, $sub, $items] = $this->lock($subOrderId, $expectedVersion);

            $lines = $this->pickLines($items, $qtyByItem, 'challan');
            $challan = Challan::query()->create([
                'number' => DocumentNumber::challan($sub->branch, DocumentNumber::now()),
                'order_id' => $order->id,
                'sub_order_id' => $sub->id,
                'branch' => $sub->branch,
                'address_key' => AddressKey::of($order),
                'created_by' => $adminId,
            ]);
            foreach ($lines as [$item, $qty]) {
                $orderItem = OrderItem::query()->findOrFail($item->order_item_id);
                // Order-time snapshot; older orders without one are snapshotted now, at the latest.
                $tax = $orderItem->gst_rate !== null
                    ? ['gst_rate' => (float) $orderItem->gst_rate, 'hsn' => $orderItem->hsn]
                    : TaxSnapshot::forProduct($orderItem->product_id, $orderItem->variant_id);
                if ($tax['gst_rate'] === null) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, "No GST rate is set for {$item->part_no}. Set it on the product before making a challan.");
                }
                $line = $challan->items()->create([
                    ...$tax,
                    'sub_order_item_id' => $item->id,
                    'order_item_id' => $item->order_item_id,
                    'part_no' => $item->part_no,
                    'title' => $orderItem->title,
                    'unit_price' => $orderItem->unit_price,
                    'qty' => $qty,
                ]);
                B2bPush::query()->create(['challan_item_id' => $line->id, 'status' => B2bPush::PENDING]);
                $item->update(['qty_challaned' => $item->qty_challaned + $qty]);
            }
            $sub->update(['version' => $sub->version + 1]);

            return $challan;
        });

        $pushIds = B2bPush::query()
            ->whereIn('challan_item_id', $challan->items()->pluck('id'))
            ->pluck('id');
        foreach ($pushIds as $pushId) {
            PushChallanItemToB2b::dispatch($pushId);
        }

        return $challan;
    }

    /** @param  array<int, int>  $qtyByItem  sub_order_item_id => qty */
    public function cancel(int $subOrderId, int $expectedVersion, array $qtyByItem): void
    {
        DB::transaction(function () use ($subOrderId, $expectedVersion, $qtyByItem) {
            [$order, $sub, $items] = $this->lock($subOrderId, $expectedVersion);

            foreach ($this->pickLines($items, $qtyByItem, 'cancel') as [$item, $qty]) {
                $item->update(['qty_cancelled' => $item->qty_cancelled + $qty]);
                OrderItem::query()->whereKey($item->order_item_id)->increment('qty_cancelled', $qty);
            }
            $sub->update(['version' => $sub->version + 1]);
            $this->allocator->finish($order);
        });
    }

    /** @return array{0: Order, 1: SubOrder, 2: Collection<int, SubOrderItem>} */
    private function lock(int $subOrderId, int $expectedVersion): array
    {
        $orderId = SubOrder::query()->whereKey($subOrderId)->value('order_id')
            ?? abort(Response::HTTP_NOT_FOUND, 'Sub-order not found.');
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $sub = SubOrder::query()->whereKey($subOrderId)->lockForUpdate()->firstOrFail();

        if ($order->status !== 'processing') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Order is {$order->status}; sub-orders can no longer change.");
        }
        if ($sub->version !== $expectedVersion) {
            abort(Response::HTTP_CONFLICT, 'This sub-order was changed by someone else. Reload and try again.');
        }

        return [$order, $sub, $sub->items()->lockForUpdate()->get()->keyBy('id')];
    }

    /**
     * @param  Collection<int, SubOrderItem>  $items
     * @param  array<int, int>  $qtyByItem
     * @return list<array{0: SubOrderItem, 1: int}>
     */
    private function pickLines(Collection $items, array $qtyByItem, string $verb): array
    {
        $lines = [];
        foreach ($qtyByItem as $id => $qty) {
            if ($qty === 0) {
                continue;
            }
            $item = $items->get($id) ?? abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Line {$id} is not on this sub-order.");
            $pending = $item->pendingQty();
            if ($qty > $pending) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, "{$item->part_no}: {$verb} {$qty} but only {$pending} is pending.");
            }
            $lines[] = [$item, $qty];
        }
        if ($lines === []) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Enter at least one quantity.');
        }

        return $lines;
    }
}
