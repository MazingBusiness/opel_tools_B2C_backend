<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\CategoryGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CategoryGroup
 */
class CategoryGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
