<?php

namespace App\Modules\Fulfilment\Console;

use App\Modules\Fulfilment\Jobs\PushChallanItemToB2b;
use App\Modules\Fulfilment\Models\B2bPush;
use Illuminate\Console\Command;

/**
 * Recovers pushes a crash left behind:
 *  - `sending` too long: the worker died mid-request, so B2B may have deducted -> needs_check.
 *  - `pending` with no job: the process died between commit and dispatch -> re-queue
 *    (the job is unique, so a push that still has a queued job is not doubled).
 */
class SweepB2bPushesCommand extends Command
{
    protected $signature = 'fulfilment:sweep-b2b-pushes {--stuck-minutes=10} {--orphan-minutes=5}';

    protected $description = 'Flag B2B pushes stuck mid-request and re-queue orphaned pending ones';

    public function handle(): int
    {
        $stuck = B2bPush::query()
            ->where('status', B2bPush::SENDING)
            ->where('last_attempt_at', '<', now()->subMinutes((int) $this->option('stuck-minutes')))
            ->update([
                'status' => B2bPush::NEEDS_CHECK,
                'last_error' => 'The push was interrupted mid-request; check with B2B whether it was received.',
                'updated_at' => now(),
            ]);

        $orphans = B2bPush::query()
            ->where('status', B2bPush::PENDING)
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('orphan-minutes')))
            ->pluck('id');
        foreach ($orphans as $id) {
            PushChallanItemToB2b::dispatch($id);
        }

        $this->info("Flagged {$stuck} stuck, re-queued {$orphans->count()} pending.");

        return self::SUCCESS;
    }
}
