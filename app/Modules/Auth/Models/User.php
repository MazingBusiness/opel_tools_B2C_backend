<?php

namespace App\Modules\Auth\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'avatar', 'firebase_uid', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * disabled_at is intentionally NOT fillable: only the admin
     * activate/deactivate endpoints may change it (via forceFill).
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function profileComplete(): bool
    {
        return filled($this->name);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(\App\Modules\Wishlist\Models\WishlistItem::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(\App\Modules\Cart\Models\CartItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(\App\Modules\Address\Models\Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\App\Modules\Order\Models\Order::class);
    }
}
