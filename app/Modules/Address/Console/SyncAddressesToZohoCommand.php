<?php

namespace App\Modules\Address\Console;

use App\Modules\Address\Jobs\SyncAddressToZoho;
use App\Modules\Address\Models\Address;
use App\Modules\Address\Services\ZohoBooksContacts;
use Illuminate\Console\Command;

/** Queues every address that has no Zoho customer yet. Safe to re-run: the job upserts by party code. */
class SyncAddressesToZohoCommand extends Command
{
    protected $signature = 'zoho:sync-addresses';

    protected $description = 'Queue a Zoho Books customer upsert for every address without a Zoho contact id';

    public function handle(ZohoBooksContacts $zoho): int
    {
        if (! $zoho->enabled()) {
            $this->error('Zoho Books is not configured. Set the ZOHO_BOOKS_* values in .env first.');

            return self::FAILURE;
        }

        $queued = 0;
        Address::query()->whereNull('zoho_contact_id')->whereNotNull('party_code')->select('id')
            ->chunkById(500, function ($addresses) use (&$queued): void {
                foreach ($addresses as $address) {
                    SyncAddressToZoho::dispatch((int) $address->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} address(es) for Zoho Books.");

        return self::SUCCESS;
    }
}
