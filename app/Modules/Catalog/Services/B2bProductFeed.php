<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class B2bProductFeed
{
    /**
     * Fetch the whole B2B catalogue in one call (GET {base_url}/products/all,
     * bearer B2B_STOCK_TOKEN). The token never goes in the URL, logs or errors.
     *
     * A 200 whose body is not `{res: true, data: {data: [...]}}` is retried.
     * `complete` is true only when B2B says it sent everything it counted
     * (total === rows received, last_page === 1); only then may missing
     * products be unpublished.
     *
     * @return array{items: list<mixed>, total: int, complete: bool}
     */
    public function fetchAll(): array
    {
        $base = rtrim((string) config('b2b_api.base_url'), '/');
        $token = (string) config('b2b_api.token');
        if ($base === '') {
            throw new RuntimeException('B2B_API_BASE_URL is not set.');
        }
        if ($token === '') {
            throw new RuntimeException('B2B_STOCK_TOKEN is not set.');
        }

        $url = $base.config('catalog.b2b.products_path', '/products/all');
        $timeout = max(10, (int) config('catalog.b2b.timeout', 120));
        $tries = max(1, (int) config('catalog.b2b.retry', 3));
        $pendingError = null;

        for ($try = 1; $try <= $tries; $try++) {
            try {
                $response = Http::withToken($token)->timeout($timeout)->acceptJson()->get($url);
            } catch (Throwable $e) {
                $pendingError = new RuntimeException('B2B product list request failed: '.$e->getMessage(), 0, $e);
                $this->pauseBeforeRetry($try, $tries);

                continue;
            }

            if ($response->failed()) {
                $pendingError = new UnexpectedCatalogPayload($this->unexpectedMessage($response, $response->json()));
                $this->pauseBeforeRetry($try, $tries);

                continue;
            }

            try {
                return $this->payloadFromResponse($response);
            } catch (UnexpectedCatalogPayload $e) {
                $pendingError = $e;
                $this->pauseBeforeRetry($try, $tries);
            }
        }

        throw $pendingError;
    }

    /**
     * @return array{items: list<mixed>, total: int, complete: bool}
     */
    private function payloadFromResponse(Response $response): array
    {
        $json = $response->json(null, null, JSON_INVALID_UTF8_SUBSTITUTE);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        $items = is_array($data) ? ($data['data'] ?? null) : null;

        if (! is_array($json) || ($json['res'] ?? false) !== true || ! is_array($data) || ! is_array($items)) {
            throw new UnexpectedCatalogPayload($this->unexpectedMessage($response, $json));
        }

        $items = array_values($items);
        $total = is_numeric($data['total'] ?? null) ? (int) $data['total'] : -1;
        $lastPage = is_numeric($data['last_page'] ?? null) ? (int) $data['last_page'] : 1;

        return [
            'items' => $items,
            'total' => $total,
            'complete' => $total === count($items) && $lastPage <= 1 && $items !== [],
        ];
    }

    private function unexpectedMessage(Response $response, mixed $json): string
    {
        $detail = 'HTTP '.$response->status();

        if (is_array($json)) {
            $message = trim((string) ($json['message'] ?? $json['msg'] ?? ''));
            if ($message !== '') {
                $detail .= ': '.substr($message, 0, 200);
            }
        }

        if (! str_contains($detail, ':')) {
            $body = trim(preg_replace('/\s+/', ' ', substr($response->body(), 0, 200)) ?? '');
            $detail .= $body === '' ? ', empty body' : ', body: '.substr($body, 0, 80);
        }

        return 'B2B product list returned an unexpected payload ('.$detail.').';
    }

    private function pauseBeforeRetry(int $try, int $tries): void
    {
        if ($try >= $tries || app()->runningUnitTests()) {
            return;
        }

        sleep(min(30, 5 * $try));
    }
}
