<?php

namespace App\Modules\Fulfilment\Jobs;

use App\Modules\Fulfilment\Models\B2bPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Deduct B2B stock for one challan line (POST /order-item).
 * The row is claimed as `sending` before the call, so a redelivered job (worker
 * crash mid-request) or a racing admin retry never posts twice.
 * Retries only on a clear failure: the request never left (DNS, connection refused)
 * or B2B answered success:false. Anything else may have deducted stock, so it becomes
 * needs_check for an admin. Never logs the token.
 */
class PushChallanItemToB2b implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $uniqueFor = 3600;

    public function __construct(public int $pushId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->pushId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $push = DB::transaction(function () {
            $push = B2bPush::query()->whereKey($this->pushId)->lockForUpdate()->first();
            if (! $push || ! in_array($push->status, [B2bPush::PENDING, B2bPush::FAILED], true)) {
                return null;
            }
            $push->update(['status' => B2bPush::SENDING, 'attempts' => $push->attempts + 1, 'last_attempt_at' => now()]);

            return $push;
        });
        if (! $push) {
            return;
        }

        $line = $push->challanItem()->with('challan')->firstOrFail();
        $token = config('b2b_api.token');
        $warehouseId = config('branches.'.$line->challan->branch.'.b2b_warehouse_id');
        if (blank($token) || blank($warehouseId)) {
            $this->markFailed($push, 'B2B token or warehouse id is not configured.', retry: false);

            return;
        }

        try {
            $response = Http::withToken((string) $token)
                ->acceptJson()
                ->timeout((int) config('b2b_api.timeout'))
                ->post(config('b2b_api.base_url').'/order-item', [
                    'part_no' => $line->part_no,
                    'order_id' => $line->challan->sub_order_id,
                    'qty' => $line->qty,
                    'price' => (float) $line->unit_price,
                    'warehouse_id' => (int) $warehouseId,
                ]);
        } catch (ConnectionException $e) {
            if (self::neverSent($e->getMessage())) {
                $this->markFailed($push, 'Could not connect to B2B.', retry: true);
            } else {
                $this->needsCheck($push, 'Connection to B2B broke after sending (timeout or reset); stock may or may not have been deducted.');
            }

            return;
        }

        $this->record($push, $response);
    }

    /** cURL 6 (DNS) / 7 (refused) fail before the request is written; everything else may have reached B2B. */
    public static function neverSent(string $message): bool
    {
        return (bool) preg_match('/cURL error (6|7):|Could not resolve host|Connection refused|Failed to connect/i', $message);
    }

    private function record(B2bPush $push, Response $response): void
    {
        $body = $response->json();
        $success = is_array($body) ? ($body['success'] ?? null) : null;

        if ($response->successful() && $success === true) {
            $push->update([
                'status' => B2bPush::SENT,
                'sent_at' => now(),
                'last_error' => null,
                'b2c_order_record_id' => isset($body['data']['b2c_order_record_id']) ? (string) $body['data']['b2c_order_record_id'] : null,
            ]);

            return;
        }

        $message = is_array($body) && is_string($body['message'] ?? null) ? Str::limit($body['message'], 300) : null;
        if ($success === false) {
            $this->markFailed($push, "B2B rejected it ({$response->status()}): ".($message ?? 'no message'), retry: true);

            return;
        }

        $this->needsCheck($push, "Unclear B2B answer ({$response->status()}): ".($message ?? 'no success flag'));
    }

    private function markFailed(B2bPush $push, string $error, bool $retry): void
    {
        $push->update(['status' => B2bPush::FAILED, 'last_error' => $error]);
        if ($retry && $this->job && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 900);
        }
    }

    private function needsCheck(B2bPush $push, string $error): void
    {
        $push->update(['status' => B2bPush::NEEDS_CHECK, 'last_error' => $error]);
    }
}
