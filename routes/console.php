<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('catalog:sync-b2b')
    ->weeklyOn(Carbon::SATURDAY, '02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(180);
