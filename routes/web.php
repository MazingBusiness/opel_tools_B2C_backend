<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'mazing-b2c',
        'docs' => '/api/v1/health',
    ]);
});
