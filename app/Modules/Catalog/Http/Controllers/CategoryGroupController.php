<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\CategoryGroupResource;
use App\Modules\Catalog\Models\CategoryGroup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $groups = CategoryGroup::query()
            ->where('is_active', true)
            ->with(['categories' => function ($query): void {
                $query->where('is_active', true)->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return CategoryGroupResource::collection($groups);
    }
}
