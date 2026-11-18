<?php

namespace App\Modules\Address\Console;

use App\Modules\Address\Http\Controllers\ZohoBooksOAuthController;
use App\Modules\Address\Services\ZohoBooksContacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Prints a one-time Zoho consent link. Whoever owns the Zoho Books org opens it and approves. */
class ZohoBooksConnectCommand extends Command
{
    protected $signature = 'zoho:books-connect';

    protected $description = 'Print a Zoho Books consent link; approving it stores the refresh token encrypted in the database';

    public function handle(ZohoBooksContacts $zoho): int
    {
        if (blank(config('zoho_books.client_id')) || blank(config('zoho_books.client_secret'))) {
            $this->error('Set ZOHO_BOOKS_CLIENT_ID and ZOHO_BOOKS_CLIENT_SECRET in .env first.');

            return self::FAILURE;
        }

        $state = Str::random(40);
        Cache::put(ZohoBooksOAuthController::STATE_PREFIX.$state, true, now()->addMinutes(15));

        $this->info('Open this link within 15 minutes, signed in as the Zoho Books admin, and click Accept:');
        $this->line($zoho->authUrl($state));
        $this->line('Redirect URI in use: '.config('zoho_books.redirect_uri'));

        return self::SUCCESS;
    }
}
