<?php

namespace App\Modules\Catalog\Console;

use Database\Seeders\CatalogSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/** Guarded full catalog reset (delete + reload). Requires CATALOG_SEED_ALLOW_RESET=true. */
class ResetCatalogSeedCommand extends Command
{
    protected $signature = 'catalog:reset-seed';

    protected $description = 'DELETE and reload the B2B catalog tables from the seed fixture (guarded; overrides and seo_meta are kept)';

    public function handle(): int
    {
        try {
            (new CatalogSeeder)->setContainer($this->laravel)->setCommand($this)->resetAndReload();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Catalog reset and reloaded.');

        return self::SUCCESS;
    }
}
