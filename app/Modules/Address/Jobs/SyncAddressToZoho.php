<?php

namespace App\Modules\Address\Jobs;

use App\Modules\Address\Models\Address;
use App\Modules\Address\Services\ZohoBooksContacts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAddressToZoho implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $uniqueFor = 3600;

    public function __construct(public int $addressId) {}

    public function uniqueId(): string
    {
        return (string) $this->addressId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(ZohoBooksContacts $zoho): void
    {
        if (! $zoho->enabled()) {
            return;
        }

        $address = Address::query()->find($this->addressId);
        if ($address === null || $address->zoho_contact_id !== null || $address->party_code === null) {
            return;
        }

        $contactId = $zoho->upsert($address);

        $address->forceFill([
            'zoho_contact_id' => $contactId,
            'zoho_synced_at' => now(),
        ])->save();
    }
}
