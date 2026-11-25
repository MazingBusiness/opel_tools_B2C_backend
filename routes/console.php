<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('catalog:sync-b2b')
    ->dailyAt('02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(60);

Schedule::command('fulfilment:sweep-b2b-pushes')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
