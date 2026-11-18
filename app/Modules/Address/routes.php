<?php

use App\Modules\Address\Http\Controllers\AddressController;
use App\Modules\Address\Http\Controllers\ZohoBooksOAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'shopper.active', 'throttle:addresses'])->prefix('addresses')->group(function (): void {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::patch('/{id}', [AddressController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [AddressController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/default', [AddressController::class, 'setDefault'])->whereNumber('id');
});

Route::get('/zoho/books/oauth/callback', [ZohoBooksOAuthController::class, 'callback'])->middleware('throttle:10,1');
