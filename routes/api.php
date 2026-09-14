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
});
