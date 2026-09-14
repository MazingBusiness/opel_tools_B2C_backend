<?php

use App\Modules\Catalog\Http\Controllers\BrandController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\CategoryGroupController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Middleware\CacheCatalogResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:catalog', CacheCatalogResponse::class])->group(function (): void {
    Route::get('/category-groups', [CategoryGroupController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
});
