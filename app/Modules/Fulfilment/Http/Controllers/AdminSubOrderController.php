<?php

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Fulfilment\Models\SubOrder;
use App\Modules\Fulfilment\Services\SubOrderService;
use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSubOrderController extends Controller
{
    public function __construct(private readonly SubOrderService $service) {}

    /** Fully invoiced sub-orders are hidden unless ?include_invoiced=1. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['nullable', Rule::in(Branches::codes())],
            'q' => ['nullable', 'string', 'max:64'],
            'include_invoiced' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = AdminAccess::scopeBranches(SubOrder::query(), $request)
            ->with(['order:id,number,shipping_name,shipping_city,user_id', 'items', 'challans:id,sub_order_id,invoice_id'])
            ->when($data['branch'] ?? null, fn (Builder $q, $b) => $q->where('branch', $b))
            ->when($data['q'] ?? null, fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$s}%")
                ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', "%{$s}%")->orWhere('shipping_name', 'like', "%{$s}%"))))
            ->when(! ($data['include_invoiced'] ?? false), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('items', fn (Builder $i) => $i->whereRaw('qty > qty_challaned + qty_cancelled'))
                ->orWhereHas('challans', fn (Builder $c) => $c->whereNull('invoice_id'))))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (SubOrder $s) => $this->summary($s))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeBranch($request, $id);

        return $this->payload($id);
    }

    public function challan(Request $request, int $id): JsonResponse
    {
        $this->authorizeBranch($request, $id);
        $data = $this->validateLines($request);
        $challan = $this->service->createChallan($id, $data['expected_version'], $data['lines'], $request->user()?->getAuthIdentifier());

        return $this->payload($id, ['challan_id' => $challan->id, 'challan_number' => $challan->number]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeBranch($request, $id);
        $data = $this->validateLines($request);
        $this->service->cancel($id, $data['expected_version'], $data['lines']);

        return $this->payload($id);
    }

    /** @return array{expected_version: int, lines: array<int, int>} */
    private function validateLines(Request $request): array
    {
        $v = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sub_order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        return [
            'expected_version' => (int) $v['expected_version'],
            'lines' => collect($v['lines'])->mapWithKeys(fn ($l) => [(int) $l['sub_order_item_id'] => (int) $l['qty']])->all(),
        ];
    }

    public static function status(SubOrder $s): string
    {
        $qty = $s->items->sum('qty');
        $cancelled = $s->items->sum('qty_cancelled');
        $challaned = $s->items->sum('qty_challaned');
        $pending = $qty - $cancelled - $challaned;
        if ($cancelled === $qty) {
            return 'cancelled';
        }
        if ($pending > 0) {
            return $challaned > 0 ? 'partially_challaned' : 'pending';
        }

        return $s->challans->whereNull('invoice_id')->isEmpty() ? 'invoiced' : 'challaned';
    }

    private function summary(SubOrder $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'branch' => $s->branch,
            'status' => self::status($s),
            'order_number' => $s->order?->number,
            'customer' => $s->order?->shipping_name,
            'city' => $s->order?->shipping_city,
            'qty' => $s->items->sum('qty'),
            'qty_challaned' => $s->items->sum('qty_challaned'),
            'qty_cancelled' => $s->items->sum('qty_cancelled'),
            'qty_pending' => $s->items->sum(fn ($i) => $i->pendingQty()),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private function authorizeBranch(Request $request, int $id): void
    {
        AdminAccess::assertBranch($request, SubOrder::query()->whereKey($id)->value('branch'));
    }

    private function payload(int $id, array $extra = []): JsonResponse
    {
        $s = SubOrder::query()->with([
            'order', 'items.orderItem:id,title,unit_price', 'challans.items', 'challans.invoice:id,number',
        ])->findOrFail($id);

        return response()->json(array_merge([
            'ok' => true,
            'sub_order' => array_merge($this->summary($s), [
                'version' => $s->version,
                'order_status' => $s->order->status,
                'shipping' => [
                    'name' => $s->order->shipping_name, 'phone' => $s->order->shipping_phone,
                    'line1' => $s->order->shipping_line1, 'line2' => $s->order->shipping_line2,
                    'city' => $s->order->shipping_city, 'state' => $s->order->shipping_state,
                    'pincode' => $s->order->shipping_pincode,
                ],
                'items' => $s->items->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => $i->orderItem?->title,
                    'part_no' => $i->part_no,
                    'unit_price' => (string) $i->orderItem?->unit_price,
                    'qty' => $i->qty,
                    'qty_challaned' => $i->qty_challaned,
                    'qty_cancelled' => $i->qty_cancelled,
                    'qty_pending' => $i->pendingQty(),
                ])->values()->all(),
                'challans' => $s->challans->sortByDesc('id')->map(fn ($c) => [
                    'id' => $c->id,
                    'number' => $c->number,
                    'qty' => $c->items->sum('qty'),
                    'invoice_number' => $c->invoice?->number,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values()->all(),
            ]),
        ], $extra));
    }
}
