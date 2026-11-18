<?php

namespace App\Modules\Address\Console;

use App\Modules\Address\Services\ZohoBooksContacts;
use Illuminate\Console\Command;
use RuntimeException;

/** Lists Zoho Books contact custom fields; copy the party code row's api_name into ZOHO_BOOKS_PARTY_CODE_FIELD. */
class ZohoBooksFieldsCommand extends Command
{
    protected $signature = 'zoho:books-fields';

    protected $description = 'List Zoho Books contact custom fields with their API names';

    public function handle(ZohoBooksContacts $zoho): int
    {
        try {
            $fields = $zoho->contactCustomFields();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($fields === []) {
            $this->warn('No contact custom fields found.');

            return self::SUCCESS;
        }

        $this->table(['Label', 'API name', 'Type'], array_map(fn ($f) => [$f['label'], $f['api_name'], $f['data_type']], $fields));
        $this->line('Put the party code row\'s API name in ZOHO_BOOKS_PARTY_CODE_FIELD, then run php artisan config:clear.');

        return self::SUCCESS;
    }
}
