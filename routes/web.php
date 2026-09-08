<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'opel-b2c',
        'docs' => '/api/v1/health',
    ]);
});
