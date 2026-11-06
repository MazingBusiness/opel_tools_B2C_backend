<?php

namespace App\Modules\Catalog\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY. Dumps catalog id <-> natural-key pairs so the seed fixture's fixed
 * ids can be aligned with an existing database (e.g. production):
 *   php artisan catalog:export-ids --output=storage/app/catalog-ids.json
 * Then, locally: php artisan catalog:apply-seed-ids storage/app/catalog-ids.json
 */
class ExportCatalogIdsCommand extends Command
{
    protected $signature = 'catalog:export-ids {--output= : Write JSON here instead of stdout}';

    protected $description = 'Read-only export of catalog ids with slugs / part_no (for catalog:apply-seed-ids)';

    public function handle(): int
    {
        $data = [
            'exported_at' => now()->toIso8601String(),
            'app_env' => app()->environment(),
            'category_groups' => DB::table('category_groups')->orderBy('id')->get(['id', 'slug'])->all(),
            'categories' => DB::table('categories')->orderBy('id')->get(['id', 'slug', 'parent_id', 'category_group_id'])->all(),
            'products' => DB::table('products')->orderBy('id')->get(['id', 'slug'])->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->get(['id', 'product_id', 'part_no'])->all(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, $json);
            $this->info(sprintf(
                'Wrote %s (%d groups, %d categories, %d products, %d variants).',
                $output, count($data['category_groups']), count($data['categories']), count($data['products']), count($data['variants']),
            ));
        } else {
            $this->output->write($json);
        }

        return self::SUCCESS;
    }
}
