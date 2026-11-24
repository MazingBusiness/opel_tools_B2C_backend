<?php

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Models\B2bPush;
use App\Modules\Fulfilment\Models\Challan;
use App\Modules\Fulfilment\Models\Invoice;
use App\Modules\Fulfilment\Support\Branches;
use App\Modules\Fulfilment\Support\DocumentNumber;
use App\Modules\Fulfilment\Support\IndianState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Challans → one tax invoice. Prices are GST-inclusive, so each line is
 * back-calculated: taxable = gross × 100 / (100 + rate), tax = gross − taxable.
 * Each line keeps its own rounding, so the invoice total is exactly what the
 * customer paid for those units (round_off stays inside the invoice, normally 0).
 */
class InvoiceBuilder
{
    /** @param  list<int>  $challanIds */
    public function preview(array $challanIds): array
    {
        $challans = $this->loadChallans($challanIds, lock: false);

        return $this->compute($challans);
    }

    /** @param  list<int>  $challanIds */
    public function create(array $challanIds, ?int $adminId): Invoice
    {
        return DB::transaction(function () use ($challanIds, $adminId) {
            $challans = $this->loadChallans($challanIds, lock: true);
            $calc = $this->compute($challans);
            $first = $challans->first();
            $order = $first->order;

            $invoice = Invoice::query()->create([
                'number' => DocumentNumber::invoice($first->branch, DocumentNumber::now()),
                'branch' => $first->branch,
                'user_id' => $order->user_id,
                'address_key' => $first->address_key,
                'billing_name' => $order->shipping_name,
                'billing_phone' => $order->shipping_phone,
                'billing_line1' => $order->shipping_line1,
                'billing_line2' => $order->shipping_line2,
                'billing_city' => $order->shipping_city,
                'billing_state' => $order->shipping_state,
                'billing_pincode' => $order->shipping_pincode,
                'intra_state' => $calc['intra_state'],
                'taxable_total' => $calc['taxable_total'],
                'cgst_total' => $calc['cgst_total'],
                'sgst_total' => $calc['sgst_total'],
                'igst_total' => $calc['igst_total'],
                'round_off' => $calc['round_off'],
                'grand_total' => $calc['grand_total'],
                'created_by' => $adminId,
            ]);
            foreach ($calc['lines'] as $line) {
                $invoice->items()->create($line);
            }
            Challan::query()->whereIn('id', $challans->pluck('id'))->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Challan>
     */
    private function loadChallans(array $ids, bool $lock): Collection
    {
        $q = Challan::query()->whereIn('id', array_unique($ids))->with(['items.b2bPush', 'order']);
        if ($lock) {
            $q->lockForUpdate();
        }
        $challans = $q->orderBy('id')->get();

        if ($challans->count() !== count(array_unique($ids)) || $challans->isEmpty()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Pick at least one existing challan.');
        }
        if ($challans->whereNotNull('invoice_id')->isNotEmpty()) {
            abort(Response::HTTP_CONFLICT, 'A picked challan is already invoiced. Reload and try again.');
        }
        if ($challans->pluck('branch')->unique()->count() > 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Challans from different branches cannot share an invoice.');
        }
        if ($challans->pluck('address_key')->unique()->count() > 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Challans for different customers or addresses cannot share an invoice.');
        }
        $cancelled = $challans->first(fn (Challan $c) => $c->order->status === 'cancelled');
        if ($cancelled) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Order {$cancelled->order->number} was cancelled after challan {$cancelled->number}; it cannot be invoiced.");
        }
        $unsettled = $challans->first(fn (Challan $c) => $c->items->contains(
            fn ($i) => in_array($i->b2bPush?->status, [B2bPush::FAILED, B2bPush::NEEDS_CHECK], true)
        ));
        if ($unsettled) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Challan {$unsettled->number} has a B2B push that failed or needs a check. Settle it first.");
        }

        return $challans;
    }

    /** @param  Collection<int, Challan>  $challans */
    private function compute(Collection $challans): array
    {
        $first = $challans->first();
        $branchState = Branches::all()[$first->branch]['state'];
        $intra = IndianState::same($branchState, (string) $first->order->shipping_state);

        $cents = fn (float $v): int => (int) round($v * 100);
        $lines = [];
        $t = ['gross' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        foreach ($challans as $challan) {
            foreach ($challan->items as $item) {
                // Rate, HSN and price all come from the challan line's snapshot, never the live catalog.
                $rate = (float) $item->gst_rate;
                $gross = $cents((float) $item->unit_price) * $item->qty;
                $taxable = (int) round($gross * 100 / (100 + $rate));
                $tax = $gross - $taxable;
                $cgst = $intra ? intdiv($tax, 2) : 0;
                $sgst = $intra ? $tax - $cgst : 0;
                $igst = $intra ? 0 : $tax;

                $lines[] = [
                    'challan_item_id' => $item->id,
                    'order_id' => $challan->order_id,
                    'title' => $item->title,
                    'part_no' => $item->part_no,
                    'hsn' => $item->hsn,
                    'qty' => $item->qty,
                    'unit_price' => (string) $item->unit_price,
                    'gst_rate' => number_format($rate, 2, '.', ''),
                    'taxable' => $taxable / 100,
                    'cgst' => $cgst / 100,
                    'sgst' => $sgst / 100,
                    'igst' => $igst / 100,
                    'line_total' => $gross / 100,
                ];
                foreach (['gross', 'taxable', 'cgst', 'sgst', 'igst'] as $k) {
                    $t[$k] += ${$k};
                }
            }
        }

        $roundOff = $t['gross'] - ($t['taxable'] + $t['cgst'] + $t['sgst'] + $t['igst']);

        return [
            'branch' => $first->branch,
            'intra_state' => $intra,
            'billing' => [
                'name' => $first->order->shipping_name,
                'state' => $first->order->shipping_state,
            ],
            'challans' => $challans->pluck('number')->all(),
            'lines' => $lines,
            'taxable_total' => $t['taxable'] / 100,
            'cgst_total' => $t['cgst'] / 100,
            'sgst_total' => $t['sgst'] / 100,
            'igst_total' => $t['igst'] / 100,
            'round_off' => $roundOff / 100,
            'grand_total' => $t['gross'] / 100,
        ];
    }
}
