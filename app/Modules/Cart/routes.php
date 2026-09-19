<?php

use App\Modules\Cart\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:cart'])->prefix('cart')->group(function (): void {
    Route::get('/', [CartController::class, 'show']);
    Route::get('/count', [CartController::class, 'count']);
    Route::post('/items', [CartController::class, 'store']);
    Route::patch('/items/{itemId}', [CartController::class, 'update'])->whereNumber('itemId');
    Route::delete('/items/{itemId}', [CartController::class, 'destroy'])->whereNumber('itemId');
    Route::delete('/', [CartController::class, 'clear']);
});
