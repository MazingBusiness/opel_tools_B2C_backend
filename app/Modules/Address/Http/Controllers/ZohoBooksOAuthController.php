<?php

namespace App\Modules\Address\Http\Controllers;

use App\Modules\Address\Services\ZohoBooksContacts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZohoBooksOAuthController
{
    public const STATE_PREFIX = 'zoho_books_oauth_state:';

    public function callback(Request $request, ZohoBooksContacts $zoho): Response
    {
        $state = (string) $request->query('state', '');
        if ($state === '' || ! Cache::pull(self::STATE_PREFIX.$state)) {
            return $this->page('This link is invalid or expired. Run php artisan zoho:books-connect again.', 422);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->page('Zoho did not grant access ('.e((string) $request->query('error', 'no code')).').', 422);
        }

        try {
            $zoho->exchangeCode((string) $request->query('code'), $request->query('accounts-server'));
        } catch (RuntimeException $e) {
            Log::warning('[ZohoBooks] '.$e->getMessage());

            return $this->page('Could not connect Zoho Books. Check the server log and try again.', 502);
        }

        return $this->page('Zoho Books connected. You can close this tab.');
    }

    private function page(string $message, int $status = 200): Response
    {
        return response('<!doctype html><meta charset="utf-8"><title>Zoho Books</title><p style="font:16px system-ui;margin:3rem">'.$message.'</p>', $status)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
