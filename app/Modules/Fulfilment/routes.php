<?php

use App\Modules\Fulfilment\Http\Controllers\AdminAllocationController;
use App\Modules\Fulfilment\Http\Controllers\AdminChallanController;
use App\Modules\Fulfilment\Http\Controllers\AdminInvoiceController;
use App\Modules\Fulfilment\Http\Controllers\AdminSubOrderController;
use Illuminate\Support\Facades\Route;

// Step 1: order approval → one sub-order per branch. Whole order, so the orders module (not branch-scoped).
Route::middleware(['auth:sanctum', 'admin', 'admin.module:orders'])->prefix('admin')->group(function (): void {
    Route::prefix('orders')->group(function (): void {
        Route::get('/{number}/allocation', [AdminAllocationController::class, 'show'])->where('number', 'OPL-[A-Za-z0-9\-]+');
        Route::post('/{number}/allocate', [AdminAllocationController::class, 'allocate'])->where('number', 'OPL-[A-Za-z0-9\-]+');
        Route::post('/{number}/cancel-unallocated', [AdminAllocationController::class, 'cancel'])->where('number', 'OPL-[A-Za-z0-9\-]+');
    });
});

// Steps 2–5: branch-scoped (see AdminAccess).
Route::middleware(['auth:sanctum', 'admin', 'admin.module:fulfilment'])->prefix('admin')->group(function (): void {
    // Steps 2–3: sub-orders, challans, B2B stock pushes.
    Route::get('/sub-orders', [AdminSubOrderController::class, 'index']);
    Route::get('/sub-orders/{id}', [AdminSubOrderController::class, 'show'])->whereNumber('id');
    Route::post('/sub-orders/{id}/challans', [AdminSubOrderController::class, 'challan'])->whereNumber('id');
    Route::post('/sub-orders/{id}/cancel', [AdminSubOrderController::class, 'cancel'])->whereNumber('id');
    Route::get('/challans', [AdminChallanController::class, 'index']);
    Route::get('/challans/{id}', [AdminChallanController::class, 'show'])->whereNumber('id');
    Route::post('/b2b-pushes/{id}/retry', [AdminChallanController::class, 'retryPush'])->whereNumber('id');
    Route::post('/b2b-pushes/{id}/resolve', [AdminChallanController::class, 'resolvePush'])->whereNumber('id');

    // Steps 4–5: invoices.
    Route::get('/invoices', [AdminInvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [AdminInvoiceController::class, 'show'])->whereNumber('id');
    Route::post('/invoices/preview', [AdminInvoiceController::class, 'preview']);
    Route::post('/invoices', [AdminInvoiceController::class, 'store']);
});
