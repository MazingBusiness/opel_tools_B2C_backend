<?php

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fulfilment\Jobs\PushChallanItemToB2b;
use App\Modules\Fulfilment\Models\B2bPush;
use App\Modules\Fulfilment\Models\Challan;
use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminChallanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['nullable', Rule::in(Branches::codes())],
            'invoiced' => ['nullable', Rule::in(['yes', 'no'])],
            'q' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Challan::query()
            ->with(['order:id,number,shipping_name,shipping_city', 'subOrder:id,number', 'invoice:id,number', 'items.b2bPush'])
            ->when($data['branch'] ?? null, fn (Builder $q, $b) => $q->where('branch', $b))
            ->when(($data['invoiced'] ?? null) === 'no', fn (Builder $q) => $q->whereNull('invoice_id'))
            ->when(($data['invoiced'] ?? null) === 'yes', fn (Builder $q) => $q->whereNotNull('invoice_id'))
            ->when($data['q'] ?? null, fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$s}%")
                ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', "%{$s}%")->orWhere('shipping_name', 'like', "%{$s}%"))))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (Challan $c) => $this->summary($c))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $c = Challan::query()->with(['order', 'subOrder:id,number', 'invoice:id,number', 'items.b2bPush'])->findOrFail($id);

        return response()->json(['ok' => true, 'challan' => array_merge($this->summary($c), [
            'shipping' => [
                'name' => $c->order->shipping_name, 'phone' => $c->order->shipping_phone,
                'line1' => $c->order->shipping_line1, 'line2' => $c->order->shipping_line2,
                'city' => $c->order->shipping_city, 'state' => $c->order->shipping_state,
                'pincode' => $c->order->shipping_pincode,
            ],
            'items' => $c->items->map(fn ($i) => [
                'id' => $i->id,
                'title' => $i->title,
                'part_no' => $i->part_no,
                'qty' => $i->qty,
                'unit_price' => (string) $i->unit_price,
                'push' => $i->b2bPush ? [
                    'id' => $i->b2bPush->id,
                    'status' => $i->b2bPush->status,
                    'attempts' => $i->b2bPush->attempts,
                    'b2c_order_record_id' => $i->b2bPush->b2c_order_record_id,
                    'last_error' => $i->b2bPush->last_error,
                    'sent_at' => $i->b2bPush->sent_at?->toIso8601String(),
                ] : null,
            ])->values()->all(),
        ])]);
    }

    /** Re-queue a push. A needs_check push may already have deducted stock, so it needs explicit confirmation. */
    public function retryPush(Request $request, int $pushId): JsonResponse
    {
        $data = $request->validate(['confirmed_not_received' => ['nullable', 'boolean']]);
        $push = DB::transaction(function () use ($pushId, $data) {
            $push = B2bPush::query()->whereKey($pushId)->lockForUpdate()->firstOrFail();
            if ($push->status === B2bPush::NEEDS_CHECK && ! ($data['confirmed_not_received'] ?? false)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Confirm with B2B that this line was not received before retrying.');
            }
            if (! in_array($push->status, [B2bPush::FAILED, B2bPush::NEEDS_CHECK], true)) {
                abort(Response::HTTP_CONFLICT, "Push is {$push->status}; nothing to retry.");
            }
            $push->update(['status' => B2bPush::PENDING]);

            return $push;
        });
        PushChallanItemToB2b::dispatch($push->id);

        return $this->show($push->challanItem->challan_id);
    }

    /** Admin confirmed with B2B that a needs_check line was received. */
    public function resolvePush(Request $request, int $pushId): JsonResponse
    {
        $data = $request->validate([
            'b2c_order_record_id' => ['nullable', 'string', 'max:64', 'required_without:note'],
            'note' => ['nullable', 'string', 'max:300', 'required_without:b2c_order_record_id'],
        ]);
        $adminId = $request->user()?->getAuthIdentifier();
        $push = DB::transaction(function () use ($pushId, $data, $adminId) {
            $push = B2bPush::query()->whereKey($pushId)->lockForUpdate()->firstOrFail();
            if ($push->status !== B2bPush::NEEDS_CHECK) {
                abort(Response::HTTP_CONFLICT, 'Only a push that needs a check can be marked received.');
            }
            $recordId = filled($data['b2c_order_record_id'] ?? null) ? trim($data['b2c_order_record_id']) : null;
            $push->update([
                'status' => B2bPush::SENT,
                'sent_at' => now(),
                'b2c_order_record_id' => $recordId ?? $push->b2c_order_record_id,
                'last_error' => 'Marked received by admin #'.($adminId ?? '?').' after checking with B2B'
                    .(filled($data['note'] ?? null) ? ': '.trim($data['note']) : '.'),
            ]);

            return $push;
        });

        return $this->show($push->challanItem->challan_id);
    }

    private function summary(Challan $c): array
    {
        $statuses = $c->items->map(fn ($i) => $i->b2bPush?->status)->filter();

        return [
            'id' => $c->id,
            'number' => $c->number,
            'branch' => $c->branch,
            'order_number' => $c->order?->number,
            'sub_order_id' => $c->sub_order_id,
            'sub_order_number' => $c->subOrder?->number,
            'customer' => $c->order?->shipping_name,
            'city' => $c->order?->shipping_city,
            'address_key' => $c->address_key,
            'qty' => $c->items->sum('qty'),
            'amount' => number_format($c->items->sum(fn ($i) => (float) $i->unit_price * $i->qty), 2, '.', ''),
            'invoice_id' => $c->invoice_id,
            'invoice_number' => $c->invoice?->number,
            'b2b_status' => match (true) {
                $statuses->contains(B2bPush::NEEDS_CHECK) => B2bPush::NEEDS_CHECK,
                $statuses->contains(B2bPush::FAILED) => B2bPush::FAILED,
                $statuses->contains(B2bPush::SENDING) => B2bPush::SENDING,
                $statuses->contains(B2bPush::PENDING) => B2bPush::PENDING,
                default => B2bPush::SENT,
            },
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
