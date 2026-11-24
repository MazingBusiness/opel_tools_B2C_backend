<?php

namespace App\Modules\Fulfilment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bPush extends Model
{
    public const PENDING = 'pending';

    /** Request in flight. A crash here may already have deducted stock, so it is never auto-resent. */
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NEEDS_CHECK = 'needs_check';

    protected $fillable = ['challan_item_id', 'status', 'attempts', 'b2c_order_record_id', 'last_error', 'last_attempt_at', 'sent_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function challanItem(): BelongsTo
    {
        return $this->belongsTo(ChallanItem::class);
    }
}
