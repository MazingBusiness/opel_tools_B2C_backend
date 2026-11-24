<?php

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fulfilment\Http\Requests\AllocateOrderRequest;
use App\Modules\Fulfilment\Http\Requests\CancelUnallocatedRequest;
use App\Modules\Fulfilment\Models\SubOrder;
use App\Modules\Fulfilment\Services\B2bStock;
use App\Modules\Fulfilment\Services\OrderAllocator;
use App\Modules\Fulfilment\Support\Branches;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminAllocationController extends Controller
{
    public function __construct(private readonly OrderAllocator $allocator) {}

    public function show(string $number, B2bStock $stock): JsonResponse
    {
        $order = $this->findOrder($number);
        $items = $order->items()->orderBy('id')->get();
        $allocated = OrderAllocator::allocatedByItem($order);
        $parts = $items->pluck('part_no')->filter()->unique()->values()->all();
        $live = $stock->forParts($parts);
        $pending = $this->b2cPending($parts, $order->id);

        $branches = [];
        foreach (Branches::all() as $code => $b) {
            $branches[] = ['code' => $code, 'name' => $b['name'], 'short' => $b['short']];
        }

        $lines = $items->map(function (OrderItem $item) use ($allocated, $live, $pending) {
            $part = $item->part_no;
            $stock = null;
            $status = null;
            if (filled($part)) {
                $row = $live[$part] ?? null;
                $status = match (true) {
                    $row === null => 'unavailable',
                    $row === B2bStock::NOT_FOUND => 'not_found',
                    default => 'ok',
                };
                $stock = [];
                foreach (Branches::codes() as $code) {
                    $liveQty = match ($status) {
                        'ok' => $row[$code] ?? 0,
                        'not_found' => 0,
                        default => null,
                    };
                    $p = $pending[$part][$code] ?? 0;
                    $stock[$code] = [
                        'live' => $liveQty,
                        'b2c_pending' => $p,
                        'left' => $liveQty === null ? null : $liveQty - $p,
                    ];
                }
            }

            return [
                'order_item_id' => $item->id,
                'title' => $item->title,
                'part_no' => $part,
                'unit_price' => (string) $item->unit_price,
                'ordered' => $item->qty,
                'allocated' => $allocated[$item->id] ?? 0,
                'cancelled' => $item->qty_cancelled,
                'unallocated' => OrderAllocator::unallocated($item, $allocated),
                'allocatable' => filled($part),
                'stock_available' => $status === 'ok' || $status === 'not_found',
                'stock_status' => $status,
                'stock' => $stock,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'allocation' => [
                'order_number' => $order->number,
                'approval_status' => $order->approval_status,
                'allocation_version' => $order->allocation_version,
                'blocked_reason' => OrderAllocator::blockedReason($order),
                'branches' => $branches,
                'lines' => $lines,
                'sub_orders' => $this->subOrders($order),
            ],
        ]);
    }

    public function allocate(AllocateOrderRequest $request, string $number): JsonResponse
    {
        $this->allocator->allocate(
            $number,
            (int) $request->validated('expected_version'),
            $request->plan(),
            $request->user()?->getAuthIdentifier(),
        );

        return $this->show($number, app(B2bStock::class));
    }

    public function cancel(CancelUnallocatedRequest $request, string $number): JsonResponse
    {
        $this->allocator->cancelUnallocated(
            $number,
            (int) $request->validated('expected_version'),
            $request->quantities(),
        );

        return $this->show($number, app(B2bStock::class));
    }

    private function findOrder(string $number): Order
    {
        return Order::query()->where('number', $number)->first()
            ?? abort(Response::HTTP_NOT_FOUND, 'Order not found.');
    }

    /**
     * Quantity on other orders' B2C sub-orders not yet on a challan, per part and branch.
     *
     * @param  list<string>  $parts
     * @return array<string, array<string, int>>
     */
    private function b2cPending(array $parts, int $excludeOrderId): array
    {
        if ($parts === []) {
            return [];
        }
        $rows = DB::table('sub_order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'sub_order_items.sub_order_id')
            ->whereIn('sub_order_items.part_no', $parts)
            ->where('sub_orders.order_id', '!=', $excludeOrderId)
            ->groupBy('sub_order_items.part_no', 'sub_orders.branch')
            ->select('sub_order_items.part_no', 'sub_orders.branch')
            ->selectRaw('SUM(sub_order_items.qty - sub_order_items.qty_challaned - sub_order_items.qty_cancelled) as pending')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->part_no][$r->branch] = max(0, (int) $r->pending);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function subOrders(Order $order): array
    {
        return SubOrder::query()
            ->where('order_id', $order->id)
            ->with('items.orderItem:id,title')
            ->orderBy('id')
            ->get()
            ->map(fn (SubOrder $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'branch' => $s->branch,
                'created_at' => $s->created_at?->toIso8601String(),
                'items' => $s->items->map(fn ($i) => [
                    'title' => $i->orderItem?->title,
                    'part_no' => $i->part_no,
                    'qty' => $i->qty,
                    'qty_challaned' => $i->qty_challaned,
                    'qty_cancelled' => $i->qty_cancelled,
                ])->values()->all(),
            ])->all();
    }
}
