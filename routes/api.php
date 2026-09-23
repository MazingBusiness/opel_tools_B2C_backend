<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'ok' => true,
            'service' => 'opel-b2c',
        ]);
    });

    require app_path('Modules/Auth/routes.php');
    require app_path('Modules/Catalog/routes.php');
    require app_path('Modules/Wishlist/routes.php');
    require app_path('Modules/Cart/routes.php');
    require app_path('Modules/Address/routes.php');
    require app_path('Modules/Order/routes.php');
});
