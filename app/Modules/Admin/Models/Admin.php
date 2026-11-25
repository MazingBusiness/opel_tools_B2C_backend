<?php

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Support\AdminModules;
use App\Modules\Fulfilment\Support\Branches;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Back-office account. The super admin (seeded from .env) can do everything and
 * manages sub-admins; a sub-admin sees only its modules and branches.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property bool $is_super
 * @property ?list<string> $modules
 * @property ?list<string> $branches
 * @property bool $must_change_password
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory;

    public const TOKEN_NAME = 'opel-b2c-admin';

    protected $fillable = ['name', 'email', 'phone', 'modules', 'branches'];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): AdminFactory
    {
        return AdminFactory::new();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super' => 'boolean',
            'modules' => 'array',
            'branches' => 'array',
            'must_change_password' => 'boolean',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function canUseModule(string $module): bool
    {
        return $this->is_super || in_array($module, $this->modules ?? [], true);
    }

    /** @return list<string> branch codes this admin may see */
    public function allowedBranches(): array
    {
        if ($this->is_super) {
            return Branches::codes();
        }

        return array_values(array_intersect(Branches::codes(), $this->branches ?? []));
    }

    /** @return list<string> */
    public function allowedModules(): array
    {
        return $this->is_super ? AdminModules::ALL : array_values(array_intersect(AdminModules::ALL, $this->modules ?? []));
    }

    public function revokeTokens(): void
    {
        $this->tokens()->delete();
    }
}
