<?php

use App\Modules\Admin\Http\Controllers\AdminAuthController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:admin-login');

    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])
        ->middleware('throttle:admin-password-reset');

    Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])
        ->middleware('throttle:admin-password-reset');

    Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
        Route::put('/password', [AdminAuthController::class, 'updatePassword'])
            ->middleware('throttle:admin-password-change');
        Route::post('/logout', [AdminAuthController::class, 'logout']);
    });
});

// Admin Users v0: shoppers only (staff ids 404).
Route::prefix('admin/users')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::get('/{id}', [AdminUserController::class, 'show'])->whereNumber('id');
    Route::post('/{id}/deactivate', [AdminUserController::class, 'deactivate'])->whereNumber('id');
    Route::post('/{id}/activate', [AdminUserController::class, 'activate'])->whereNumber('id');
});
