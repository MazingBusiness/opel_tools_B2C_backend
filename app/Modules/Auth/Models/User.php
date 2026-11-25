<?php

namespace App\Modules\Auth\Models;

use App\Modules\Address\Models\Address;
use App\Modules\Auth\Support\PartyCode;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Order\Models\Order;
use App\Modules\Wishlist\Models\WishlistItem;
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

    protected static function booted(): void
    {
        // The master party code comes from the id, so concurrent sign-ups can never share one.
        static::created(function (User $user): void {
            if ($user->party_code === null) {
                $user->forceFill(['party_code' => PartyCode::master((int) $user->id)])->saveQuietly();
            }
        });
    }

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
        return $this->hasMany(WishlistItem::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
