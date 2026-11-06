<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class B2bProductFeed
{
    /**
     * Fetch one listing page. Always sends `page` (and `user_id` when set).
     * Never follows next_page_url — those links drop user_id.
     *
     * A 200 whose body is `0`, HTML, or a Laravel error JSON (no `res` / `data`)
     * is retried. Those responses are what cPanel was stopping on.
     *
     * @return array{items: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function fetchPage(int $page): array
    {
        $url = (string) config('catalog.b2b.products_url');
        if ($url === '') {
            throw new RuntimeException('B2B_PRODUCTS_URL is not set.');
        }

        $query = ['page' => $page];
        $userId = config('catalog.b2b.products_user_id');
        if ($userId !== null && $userId !== '') {
            $query['user_id'] = $userId;
        }

        $tries = max(1, (int) config('catalog.b2b.retry', 3));
        $pendingError = null;

        for ($try = 1; $try <= $tries; $try++) {
            try {
                $response = Http::timeout(30)->acceptJson()->get($url, $query);
            } catch (Throwable $e) {
                $pendingError = new RuntimeException(
                    'B2B products page '.$page.' failed: '.$e->getMessage(),
                    0,
                    $e,
                );
                $this->pauseBeforeRetry($try, $tries);

                continue;
            }

            if ($response->failed()) {
                $pendingError = new RuntimeException(
                    'B2B products page '.$page.' failed: HTTP '.$response->status(),
                );
                $this->pauseBeforeRetry($try, $tries);

                continue;
            }

            try {
                return $this->payloadFromResponse($page, $response);
            } catch (UnexpectedCatalogPayload $e) {
                $pendingError = $e;
                $this->pauseBeforeRetry($try, $tries);
            }
        }

        throw $pendingError;
    }

    /**
     * @return array{items: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    private function payloadFromResponse(int $page, Response $response): array
    {
        $json = $response->json(null, null, JSON_INVALID_UTF8_SUBSTITUTE);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        $items = is_array($data) ? ($data['data'] ?? null) : null;
        $lastPage = is_array($data) ? (int) ($data['last_page'] ?? 0) : 0;
        $res = is_array($json) ? ($json['res'] ?? false) : false;

        if (! is_array($json) || ! $res || ! is_array($data) || ! is_array($items) || $lastPage < 1) {
            throw new UnexpectedCatalogPayload($this->unexpectedMessage($page, $response, $json));
        }

        return [
            'items' => array_values($items),
            'current_page' => (int) ($data['current_page'] ?? $page),
            'last_page' => $lastPage,
            'total' => (int) ($data['total'] ?? 0),
        ];
    }

    private function unexpectedMessage(int $page, Response $response, mixed $json): string
    {
        $detail = 'HTTP '.$response->status();

        if (is_array($json)) {
            $message = trim((string) ($json['message'] ?? $json['msg'] ?? ''));
            if ($message !== '') {
                $detail .= ': '.$message;
            }
        }

        if (! str_contains($detail, ':')) {
            $body = trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');
            $detail .= $body === '' ? ', empty body' : ', body: '.substr($body, 0, 80);
        }

        return 'B2B products page '.$page.' returned an unexpected payload ('.$detail.').';
    }

    private function pauseBeforeRetry(int $try, int $tries): void
    {
        if ($try >= $tries || app()->runningUnitTests()) {
            return;
        }

        usleep(500_000);
    }
}
