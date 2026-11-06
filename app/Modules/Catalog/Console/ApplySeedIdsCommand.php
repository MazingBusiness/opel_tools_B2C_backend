<?php

namespace App\Modules\Catalog\Console;

use Illuminate\Console\Command;

/**
 * Rewrites the seed fixture's fixed ids from a catalog:export-ids JSON:
 * products + variants by part_no, categories + groups by slug.
 * Backs the fixture up to ~/opel_backups/ before writing. Touches no database.
 */
class ApplySeedIdsCommand extends Command
{
    protected $signature = 'catalog:apply-seed-ids
        {export : Path to the JSON written by catalog:export-ids}
        {--fixture= : Fixture path (default database/seeders/data/b2b-air-blowers.json)}
        {--assign-missing : Give items missing from the export new ids above every known id}
        {--dry-run : Report only, do not write}';

    protected $description = 'Set fixed ids in the catalog seed fixture from a catalog:export-ids dump';

    public function handle(): int
    {
        $exportPath = (string) $this->argument('export');
        $fixturePath = (string) ($this->option('fixture') ?: database_path('seeders/data/b2b-air-blowers.json'));

        $export = json_decode((string) @file_get_contents($exportPath), true);
        $items = json_decode((string) @file_get_contents($fixturePath), true);
        if (! is_array($export) || ! is_array($items)) {
            $this->error('Could not read the export or the fixture as JSON.');

            return self::FAILURE;
        }

        $groupBySlug = array_column($export['category_groups'] ?? [], 'id', 'slug');
        $categoryBySlug = array_column($export['categories'] ?? [], 'id', 'slug');
        $variantByPart = [];
        foreach ($export['variants'] ?? [] as $v) {
            $variantByPart[(string) $v['part_no']] = ['id' => (int) $v['id'], 'product_id' => (int) $v['product_id']];
        }

        $next = [
            'product' => max([0, ...array_column($export['products'] ?? [], 'id'), ...array_column($items, 'id')]) + 1,
            'variant' => max([0, ...array_column($export['variants'] ?? [], 'id'), ...array_column($items, 'variant_id')]) + 1,
        ];

        $missing = [];
        $changes = 0;
        foreach ($items as $i => $item) {
            $part = (string) ($item['part_no'] ?? '');
            $match = $variantByPart[$part] ?? null;
            if ($match === null) {
                $missing[] = "part_no $part";
                if ($this->option('assign-missing') && ! is_int($item['id'] ?? null)) {
                    $match = ['product_id' => $next['product']++, 'id' => $next['variant']++];
                }
            }
            if ($match !== null) {
                $changes += (int) (($item['id'] ?? null) !== $match['product_id']) + (int) (($item['variant_id'] ?? null) !== $match['id']);
                $item = ['id' => $match['product_id'], 'variant_id' => $match['id']] + $item;
            }

            foreach (['category' => $categoryBySlug, 'category_group' => $groupBySlug] as $key => $bySlug) {
                $node = (array) ($item[$key] ?? []);
                $slug = $node['slug'] ?? null;
                if (! is_string($slug) || ! isset($bySlug[$slug])) {
                    $missing[] = "$key slug ".var_export($slug, true)." (item $part)";

                    continue;
                }
                $changes += (int) (($node['id'] ?? null) !== (int) $bySlug[$slug]);
                $node = ['id' => (int) $bySlug[$slug]] + $node;
                $item[$key] = $node;
            }

            $items[$i] = $item;
        }

        $missing = array_values(array_unique($missing));
        foreach ($missing as $m) {
            $this->warn("Not in export: $m");
        }
        $this->info("$changes id change(s) for ".count($items).' fixture item(s).');

        if ($missing !== [] && ! $this->option('assign-missing')) {
            $this->error('Some fixture items are not in the export; nothing written. Re-run with --assign-missing to give products/variants new ids (categories/groups must exist).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $home = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/');
        if ($home !== '') {
            $dir = $home.'/opel_backups';
            @mkdir($dir, 0755, true);
            $backup = $dir.'/'.basename($fixturePath).'.'.date('Ymd-His').'.bak';
            copy($fixturePath, $backup);
            $this->line("Backup: $backup");
        }

        $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Keep the fixture's 2-space indentation.
        $json = preg_replace_callback('/^( +)/m', fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), (string) $json);
        file_put_contents($fixturePath, $json."\n");
        $this->info("Wrote $fixturePath");

        return self::SUCCESS;
    }
}
