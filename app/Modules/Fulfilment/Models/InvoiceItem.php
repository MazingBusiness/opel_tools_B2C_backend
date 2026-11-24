<?php

namespace App\Modules\Fulfilment\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer', 'unit_price' => 'decimal:2', 'gst_rate' => 'decimal:2', 'taxable' => 'decimal:2',
            'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'igst' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }
}
