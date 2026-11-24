<?php

namespace App\Modules\Fulfilment\Support;

use Illuminate\Support\Facades\DB;

/**
 * GST rate + HSN as they are right now, for snapshotting onto an order line
 * (at placement) or a challan line (fallback for older orders). Invoices only
 * ever read the snapshot, never the live catalog.
 */
final class TaxSnapshot
{
    /** @return array{gst_rate: ?float, hsn: ?string}  null rate = not configured; never guessed */
    public static function forProduct(?int $productId, ?int $variantId = null): array
    {
        $rate = $productId === null ? null : DB::table('product_taxes')
            ->join('taxes', 'taxes.id', '=', 'product_taxes.tax_id')
            ->where('product_taxes.product_id', $productId)
            ->orderBy('product_taxes.id')
            ->value('taxes.rate');
        $hsn = $variantId === null ? null : DB::table('product_variants')->where('id', $variantId)->value('hsncode');
        $hsn = filled($hsn) ? $hsn : ($productId === null ? null : DB::table('products')->where('id', $productId)->value('hsncode'));

        return [
            'gst_rate' => $rate === null ? null : (float) $rate,
            'hsn' => filled($hsn) ? (string) $hsn : null,
        ];
    }
}
