<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Admin\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Admin
 */
class AdminResource extends JsonResource
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
            'is_super' => (bool) $this->is_super,
            'modules' => $this->allowedModules(),
            'branches' => $this->allowedBranches(),
            'must_change_password' => (bool) $this->must_change_password,
            'disabled' => $this->isDisabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
