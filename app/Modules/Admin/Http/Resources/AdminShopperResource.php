<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shopper row for the admin Users list.
 *
 * @mixin User
 */
class AdminShopperResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->isDisabled() ? 'disabled' : 'active',
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'profile_complete' => $this->profileComplete(),
            'google_linked' => filled($this->firebase_uid),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'orders_count' => $this->whenCounted('orders', fn () => (int) $this->orders_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
