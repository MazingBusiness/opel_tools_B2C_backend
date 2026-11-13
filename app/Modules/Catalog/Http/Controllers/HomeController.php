<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\HomePage;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function show(HomePage $home): JsonResponse
    {
        return response()->json(['data' => $home->resolve()]);
    }
}
