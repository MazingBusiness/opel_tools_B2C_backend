<?php

use App\Modules\Seo\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'opel-b2c',
        'docs' => '/api/v1/health',
    ]);
});

// Storefront SEO files (Netlify proxies /sitemap.xml and /robots.txt here).
// Stateless: strip the web group (session, cookies, CSRF) so responses stay cacheable.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->middleware('throttle:60,1')->withoutMiddleware('web');
Route::get('/robots.txt', [SeoController::class, 'robots'])->middleware('throttle:60,1')->withoutMiddleware('web');
