<?php

namespace App\Modules\Address\Providers;

use App\Modules\Address\Console\SyncAddressesToZohoCommand;
use App\Modules\Address\Console\ZohoBooksConnectCommand;
use App\Modules\Address\Console\ZohoBooksFieldsCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AddressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncAddressesToZohoCommand::class, ZohoBooksConnectCommand::class, ZohoBooksFieldsCommand::class]);
        }

        RateLimiter::for('addresses', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
