<?php

namespace App\Modules\Fulfilment\Providers;

use App\Modules\Fulfilment\Console\SweepB2bPushesCommand;
use Illuminate\Support\ServiceProvider;

class FulfilmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SweepB2bPushesCommand::class]);
        }
    }
}
