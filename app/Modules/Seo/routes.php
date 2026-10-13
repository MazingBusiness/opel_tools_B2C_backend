<?php

use App\Modules\Seo\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

// Public, read-only. Storefront edge function + React hook call this.
Route::get('/seo', [SeoController::class, 'show'])->middleware('throttle:seo'); // limiter: SeoServiceProvider
