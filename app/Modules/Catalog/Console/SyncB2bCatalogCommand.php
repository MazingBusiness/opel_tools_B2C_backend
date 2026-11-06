<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Services\B2bCatalogSync;
use App\Modules\Catalog\Services\B2bProductFeed;
use App\Modules\Catalog\Services\UnexpectedCatalogPayload;
use App\Modules\Catalog\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncB2bCatalogCommand extends Command
{
    protected $signature = 'catalog:sync-b2b {--wipe-demo-seed : Delete fixture products whose local id and part_no still match, plus their overrides and product SEO}';

    protected $description = 'Fetch B2B products page by page and upsert the local catalog by part_no';

    public function handle(B2bProductFeed $feed, B2bCatalogSync $sync): int
    {
        $seconds = max(60, (int) config('catalog.b2b.lock_seconds', 10800));
        $lock = Cache::lock('catalog:sync-b2b', $seconds);
        if (! $lock->get()) {
            $this->error('catalog:sync-b2b is already running.');

            return self::FAILURE;
        }

        try {
            return $this->runSync($feed, $sync);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('catalog:sync-b2b failed', ['message' => $e->getMessage()]);

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function runSync(B2bProductFeed $feed, B2bCatalogSync $sync): int
    {
        $wrote = false;

        try {
            return $this->syncPages($feed, $sync, $wrote);
        } catch (Throwable $e) {
            if ($wrote) {
                CatalogCache::bump();
            }
            throw $e;
        }
    }

    private function syncPages(B2bProductFeed $feed, B2bCatalogSync $sync, bool &$wrote): int
    {
        $now = now();
        $taxId = $sync->upsertTax($now);

        if ($this->option('wipe-demo-seed')) {
            $deleted = $sync->wipeDemoSeed();
            $this->info("Wiped {$deleted} demo-seed product(s).");
            if ($deleted > 0) {
                $wrote = true;
            }
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $page = 1;
        $lastPage = 1;
        $failedPages = 0;
        $consecutiveFailures = 0;

        do {
            try {
                $payload = $feed->fetchPage($page);
            } catch (UnexpectedCatalogPayload $e) {
                if ($page === 1) {
                    throw $e;
                }

                $failedPages++;
                $consecutiveFailures++;
                Log::warning('catalog:sync-b2b skipped a page', [
                    'page' => $page,
                    'message' => $e->getMessage(),
                ]);
                $this->warn($e->getMessage());

                if ($consecutiveFailures >= 3) {
                    throw new RuntimeException(
                        'catalog:sync-b2b stopped after 3 pages in a row returned an unexpected payload. '.$e->getMessage(),
                        0,
                        $e,
                    );
                }

                $page++;

                continue;
            }

            $consecutiveFailures = 0;
            $lastPage = $payload['last_page'];

            $pageInserted = 0;
            $pageUpdated = 0;

            DB::transaction(function () use ($sync, $payload, $now, $taxId, &$pageInserted, &$pageUpdated, &$skipped): void {
                foreach ($payload['items'] as $item) {
                    if (! is_array($item)) {
                        $skipped++;
                        Log::warning('catalog:sync-b2b skipped a malformed row');
                        $this->warn('Skipped a malformed row.');

                        continue;
                    }

                    $result = $sync->upsertItem($item, $now, $taxId);
                    if ($result === 'inserted') {
                        $pageInserted++;
                    } elseif ($result === 'updated') {
                        $pageUpdated++;
                    } else {
                        $this->noteSkip($item, $skipped);
                    }
                }
            });

            $inserted += $pageInserted;
            $updated += $pageUpdated;
            if ($pageInserted + $pageUpdated > 0) {
                $wrote = true;
            }

            $this->info("Synced page {$page}/{$lastPage}.");
            $page++;
        } while ($page <= $lastPage);

        $sync->syncSequences();
        if ($wrote) {
            CatalogCache::bump();
        }
        $this->info("Synced {$inserted} inserted, {$updated} updated, {$skipped} skipped.");
        if ($failedPages > 0) {
            $this->warn("Skipped {$failedPages} page(s) the B2B listing could not return.");
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $item */
    private function noteSkip(array $item, int &$skipped): void
    {
        $skipped++;
        $reason = trim((string) ($item['part_no'] ?? '')) === '' ? 'no part_no' : 'incomplete row';
        Log::warning('catalog:sync-b2b skipped a row', [
            'reason' => $reason,
            'id' => $item['id'] ?? null,
            'part_no' => $item['part_no'] ?? null,
            'name' => $item['name'] ?? null,
        ]);
        $this->warn('Skipped a row ('.$reason.').');
    }
}
