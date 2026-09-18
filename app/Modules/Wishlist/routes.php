<?php

use App\Modules\Wishlist\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:wishlist'])->prefix('wishlist')->group(function (): void {
    Route::get('/', [WishlistController::class, 'index']);
    Route::get('/count', [WishlistController::class, 'count']);
    Route::post('/', [WishlistController::class, 'store']);
    Route::post('/sync', [WishlistController::class, 'sync']);
    Route::delete('/products/{productId}', [WishlistController::class, 'destroy'])
        ->whereNumber('productId');
});
