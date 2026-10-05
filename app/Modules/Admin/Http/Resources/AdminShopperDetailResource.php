<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Address\Http\Resources\AddressResource;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;

/**
 * Shopper detail for admin: list fields + addresses + orders_count.
 * Expects `addresses` loaded and `orders` counted.
 *
 * @mixin User
 */
class AdminShopperDetailResource extends AdminShopperResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'orders_count' => (int) ($this->orders_count ?? 0),
            'addresses' => AddressResource::collection($this->whenLoaded('addresses'))->resolve($request),
        ]);
    }
}
