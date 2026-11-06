<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\CategoryGroupResource;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Support\CatalogSort;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $groups = CategoryGroup::query()
            ->visible()
            ->with(['categories' => function ($query): void {
                $query->visible()->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        foreach ($groups as $group) {
            $group->setRelation('categories', CatalogSort::byAdminOrder($group->categories));
        }

        return CategoryGroupResource::collection(CatalogSort::byAdminOrder($groups));
    }
}
