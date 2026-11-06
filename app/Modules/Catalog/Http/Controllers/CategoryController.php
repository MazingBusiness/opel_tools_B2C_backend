<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Support\CatalogSort;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // Effective values: admin-hidden excluded, admin sort order + names applied.
        $categories = Category::query()
            ->visible()
            ->orderBy('name')
            ->get();

        return CategoryResource::collection(CatalogSort::byAdminOrder($categories));
    }
}
