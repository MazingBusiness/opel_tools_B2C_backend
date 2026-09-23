<?php

use App\Modules\Order\Http\Controllers\OrderController;
use App\Modules\Order\Http\Controllers\ZohoPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/track/{number}', [OrderController::class, 'track'])
    ->middleware('throttle:30,1')
    ->where('number', 'OPL-[A-Za-z0-9\-]+');

Route::middleware(['auth:sanctum', 'throttle:orders'])->prefix('orders')->group(function (): void {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{order}/payment-status', [OrderController::class, 'paymentStatus'])
        ->where('order', '[A-Za-z0-9\-]+');
    Route::get('/{order}', [OrderController::class, 'show'])
        ->where('order', '[A-Za-z0-9\-]+');
});

Route::prefix('payments/zoho')->group(function (): void {
    Route::get('/oauth/redirect', [ZohoPaymentController::class, 'oauthRedirect']);
    Route::get('/oauth/callback', [ZohoPaymentController::class, 'oauthCallback']);
    Route::post('/webhook', [ZohoPaymentController::class, 'webhook']);
    Route::get('/return-sync', [ZohoPaymentController::class, 'returnSync']);
});
