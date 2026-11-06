<?php

namespace App\Modules\Catalog\Console;

use Illuminate\Console\Command;

/** Previously deleted and reloaded the static air-blower fixture. That fixture is test-only now. */
class ResetCatalogSeedCommand extends Command
{
    protected $signature = 'catalog:reset-seed';

    protected $description = 'Disabled: the static air-blower fixture is no longer the catalog source of truth';

    public function handle(): int
    {
        $this->error('catalog:reset-seed no longer reloads the static air-blower fixture. Use catalog:sync-b2b to refresh the catalog from B2B.');

        return self::FAILURE;
    }
}
