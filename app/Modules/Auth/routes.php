<?php

use App\Modules\Auth\Http\Controllers\GoogleAuthController;
use App\Modules\Auth\Http\Controllers\OtpController;
use App\Modules\Auth\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::middleware('throttle:otp')->group(function (): void {
        Route::post('/otp/request', [OtpController::class, 'request']);
        Route::post('/otp/verify', [OtpController::class, 'verify']);
    });

    Route::post('/google', [GoogleAuthController::class, 'store'])->middleware('throttle:google');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/logout', [ProfileController::class, 'logout']);
    });
});
