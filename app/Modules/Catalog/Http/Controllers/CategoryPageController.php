<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CategoryPage;
use Illuminate\Http\JsonResponse;

class CategoryPageController extends Controller
{
    public function show(CategoryPage $categoryPage, string $groupSlug, ?string $categorySlug = null): JsonResponse
    {
        $payload = $categoryPage->resolve($groupSlug, $categorySlug);
        if ($payload === null) {
            abort(404);
        }

        return response()->json(['data' => $payload]);
    }
}
