<?php

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live per-branch stock from the B2B single-product API. Fails soft: a part
 * we could not read maps to null ("stock unavailable"), never to 0.
 */
class B2bStock
{
    /** Returned when B2B answered cleanly but had no row for the part. */
    public const NOT_FOUND = ['_not_found' => 1];

    /**
     * @param  list<string>  $partNos
     * @return array<string, array<string, int>|null> part_no => [BRANCH => qty] | null
     */
    public function forParts(array $partNos): array
    {
        $partNos = array_values(array_unique(array_filter($partNos)));
        $out = array_fill_keys($partNos, null);
        $token = config('b2b_api.token');
        if ($partNos === [] || blank($token)) {
            return $out;
        }

        $missing = [];
        foreach ($partNos as $part) {
            $cached = Cache::get($this->cacheKey($part));
            if (is_array($cached)) {
                $out[$part] = $cached;
            } else {
                $missing[] = $part;
            }
        }
        if ($missing === []) {
            return $out;
        }

        $url = config('b2b_api.base_url').'/closing-stock/combined';
        $timeout = (int) config('b2b_api.timeout');

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $part) => $pool->as($part)
                    ->withToken((string) $token)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($url, ['part_no' => $part]),
                $missing,
            ));
        } catch (Throwable) {
            return $out;
        }

        foreach ($missing as $part) {
            $parsed = $this->parse($responses[$part] ?? null, $part);
            if ($parsed !== null) {
                Cache::put($this->cacheKey($part), $parsed, (int) config('b2b_api.stock_cache_seconds'));
            }
            $out[$part] = $parsed;
        }

        return $out;
    }

    /** @return array<string, int>|null */
    private function parse(mixed $response, string $part): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        $body = $response->json();
        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            return null;
        }
        if (($body['meta']['abm_status'] ?? null) !== 'available') {
            return null;
        }
        foreach ((array) ($body['data'] ?? []) as $row) {
            if (! is_array($row) || strcasecmp((string) ($row['part_no'] ?? ''), $part) !== 0) {
                continue;
            }
            $stock = [];
            foreach (Branches::all() as $code => $branch) {
                $stock[$code] = max(0, (int) ($row[$branch['stock_key']] ?? 0));
            }

            return $stock;
        }

        // success + available but no matching row: B2B drops zero-stock parts,
        // so this is "0 or unknown part" — caller shows it with a warning.
        return self::NOT_FOUND;
    }

    private function cacheKey(string $part): string
    {
        return 'b2b-stock:'.strtoupper($part);
    }
}
