<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\ProductIndexRequest;
use App\Modules\Catalog\Http\Resources\ProductDetailResource;
use App\Modules\Catalog\Http\Resources\ProductListResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogSearch;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, CatalogSearch $search): JsonResponse
    {
        $result = $search->paginate($request->validated());

        return ProductListResource::collection($result['products'])
            ->additional(['facets' => $result['facets']])
            ->response();
    }

    public function show(Product $product): ProductDetailResource
    {
        $product->load([
            'brand',
            'category',
            'categoryGroup',
            'variants.warehouses',
            'taxes.taxRate',
        ]);

        return new ProductDetailResource($product);
    }
}
