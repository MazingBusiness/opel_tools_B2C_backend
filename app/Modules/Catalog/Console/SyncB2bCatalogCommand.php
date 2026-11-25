<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Services\B2bCatalogSync;
use App\Modules\Catalog\Services\B2bProductFeed;
use App\Modules\Catalog\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncB2bCatalogCommand extends Command
{
    protected $signature = 'catalog:sync-b2b {--wipe-demo-seed : Delete fixture products whose local id and part_no still match, plus their overrides and product SEO}';

    protected $description = 'Fetch the full B2B product list in one call, upsert by part_no, and unpublish products B2B no longer lists';

    /** @var array{inserted: int, updated: int, skipped: int, failed: int, unpublished: int} */
    private array $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'unpublished' => 0];

    private bool $wrote = false;

    public function handle(B2bProductFeed $feed, B2bCatalogSync $sync): int
    {
        $this->raiseMemoryLimit((string) config('catalog.b2b.memory_limit', '512M'));

        $seconds = max(60, (int) config('catalog.b2b.lock_seconds', 3600));
        $lock = Cache::lock('catalog:sync-b2b', $seconds);
        if (! $lock->get()) {
            $this->error('catalog:sync-b2b is already running.');

            return self::FAILURE;
        }

        $runId = (int) DB::table('catalog_sync_runs')->insertGetId([
            'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->runSync($feed, $sync, $runId);
            $this->finishRun($runId, 'succeeded');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('catalog:sync-b2b failed', ['message' => $e->getMessage()] + $this->counts);
            $this->finishRun($runId, 'failed', $e->getMessage());

            return self::FAILURE;
        } finally {
            if ($this->wrote) {
                CatalogCache::bump();
            }
            $lock->release();
        }
    }

    private function runSync(B2bProductFeed $feed, B2bCatalogSync $sync, int $runId): void
    {
        $now = now();

        $payload = $feed->fetchAll();
        $items = $payload['items'];
        $received = count($items);
        DB::table('catalog_sync_runs')->where('id', $runId)->update([
            'expected' => max(0, $payload['total']), 'received' => $received, 'complete' => $payload['complete'],
        ]);
        $this->info("Received {$received} product(s) from B2B (total {$payload['total']}).");

        $taxId = $sync->upsertTax($now);

        if ($this->option('wipe-demo-seed')) {
            $deleted = $sync->wipeDemoSeed();
            $this->info("Wiped {$deleted} demo-seed product(s).");
            $this->wrote = $this->wrote || $deleted > 0;
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, mixed> $b2bIdByPart */
        $b2bIdByPart = [];
        $chunkSize = max(1, (int) config('catalog.b2b.chunk', 500));

        foreach (array_chunk($items, $chunkSize) as $chunk) {
            DB::transaction(function () use ($chunk, $sync, $now, $taxId, &$seen, &$b2bIdByPart): void {
                foreach ($chunk as $item) {
                    $this->syncRow($item, $sync, $now, $taxId, $seen, $b2bIdByPart);
                }
            });
        }
        unset($items, $payload['items']);

        $this->maybeUnpublish($sync, $seen, $received, $payload['complete'], $runId, $now);

        $sync->syncSequences();

        $c = $this->counts;
        $this->info("Synced {$c['inserted']} inserted, {$c['updated']} updated, {$c['skipped']} skipped, {$c['failed']} failed, {$c['unpublished']} unpublished.");
        Log::info('catalog:sync-b2b finished', ['received' => $received] + $c);
    }

    /**
     * Each row runs in its own savepoint so one bad row cannot abort the chunk.
     *
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $b2bIdByPart
     */
    private function syncRow(mixed $item, B2bCatalogSync $sync, mixed $now, int $taxId, array &$seen, array &$b2bIdByPart): void
    {
        if (! is_array($item)) {
            $this->counts['skipped']++;
            Log::warning('catalog:sync-b2b skipped a malformed row');
            $this->warn('Skipped a malformed row.');

            return;
        }

        $partNo = trim((string) ($item['part_no'] ?? ''));
        if ($partNo !== '' && array_key_exists($partNo, $b2bIdByPart)) {
            Log::warning('catalog:sync-b2b duplicate part_no in B2B list; later row wins', [
                'part_no' => $partNo, 'first_b2b_id' => $b2bIdByPart[$partNo], 'later_b2b_id' => $item['id'] ?? null,
            ]);
            $this->warn("Duplicate part_no {$partNo} (B2B ids {$b2bIdByPart[$partNo]} and ".($item['id'] ?? '?').').');
        }
        if ($partNo !== '') {
            $b2bIdByPart[$partNo] = $item['id'] ?? null;
            // B2B still lists it, whether or not our write below succeeds.
            $seen[$partNo] = true;
        }

        try {
            $result = DB::transaction(fn (): string => $sync->upsertItem($item, $now, $taxId));
        } catch (Throwable $e) {
            $this->counts['failed']++;
            Log::warning('catalog:sync-b2b row failed', [
                'id' => $item['id'] ?? null, 'part_no' => $partNo, 'message' => $e->getMessage(),
            ]);
            $this->warn("Row {$partNo} failed: ".$e->getMessage());

            return;
        }

        if ($result === 'skipped') {
            $this->noteSkip($item);

            return;
        }

        $this->counts[$result]++;
        $this->wrote = true;
        if ((float) $item['mrp'] <= 0) {
            Log::warning('catalog:sync-b2b stored a product with MRP <= 0 as unpublished', [
                'id' => $item['id'] ?? null, 'part_no' => $partNo, 'mrp' => $item['mrp'],
            ]);
        }
    }

    /**
     * Unpublish products B2B no longer lists, only when the response is
     * complete and its size is within tolerance of the last complete run.
     *
     * @param  array<string, true>  $seen
     */
    private function maybeUnpublish(B2bCatalogSync $sync, array $seen, int $received, bool $complete, int $runId, mixed $now): void
    {
        if ($this->counts['failed'] > 0) {
            $this->warn("Not unpublishing: {$this->counts['failed']} rows failed to sync.");
            Log::warning('catalog:sync-b2b skipped unpublishing: rows failed', ['failed' => $this->counts['failed']]);

            return;
        }

        if (! $complete) {
            $this->warn('Not unpublishing: the B2B response total does not match the rows received.');
            Log::warning('catalog:sync-b2b skipped unpublishing: incomplete response', ['received' => $received]);

            return;
        }

        $baseline = DB::table('catalog_sync_runs')
            ->where('id', '!=', $runId)
            ->where('status', 'succeeded')
            ->where('complete', true)
            ->orderByDesc('id')
            ->value('received');

        if ($baseline === null) {
            $this->warn('Not unpublishing: no earlier complete run to compare with. This run is now the baseline.');
            Log::info('catalog:sync-b2b recorded its first baseline', ['received' => $received]);

            return;
        }

        $tolerance = (float) config('catalog.b2b.unpublish_tolerance', 0.05);
        if (abs($received - (int) $baseline) > (int) $baseline * $tolerance) {
            $this->warn("Not unpublishing: received {$received} rows vs {$baseline} last time (more than ".($tolerance * 100).'% apart).');
            Log::warning('catalog:sync-b2b skipped unpublishing: count moved too much', ['received' => $received, 'baseline' => (int) $baseline]);

            return;
        }

        $parts = DB::transaction(fn (): array => $sync->unpublishMissing($seen, $now));
        $this->counts['unpublished'] = count($parts);
        if ($parts !== []) {
            $this->wrote = true;
            foreach (array_chunk($parts, 200) as $batch) {
                Log::info('catalog:sync-b2b unpublished products missing from B2B', ['part_nos' => $batch]);
            }
        }
    }

    /** @param  array<string, mixed>  $item */
    private function noteSkip(array $item): void
    {
        $this->counts['skipped']++;
        $reason = trim((string) ($item['part_no'] ?? '')) === '' ? 'no part_no' : 'incomplete row';
        Log::warning('catalog:sync-b2b skipped a row', [
            'reason' => $reason,
            'id' => $item['id'] ?? null,
            'part_no' => $item['part_no'] ?? null,
            'name' => $item['name'] ?? null,
        ]);
        $this->warn('Skipped a row ('.$reason.').');
    }

    private function finishRun(int $runId, string $status, ?string $message = null): void
    {
        DB::table('catalog_sync_runs')->where('id', $runId)->update($this->counts + [
            'status' => $status,
            'message' => $message === null ? null : substr($message, 0, 1000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function raiseMemoryLimit(string $wanted): void
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '-1') {
                return PHP_INT_MAX;
            }
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };

        if ($toBytes((string) ini_get('memory_limit')) < $toBytes($wanted)) {
            ini_set('memory_limit', $wanted);
        }
    }
}
