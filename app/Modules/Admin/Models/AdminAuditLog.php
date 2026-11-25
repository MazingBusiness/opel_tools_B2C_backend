<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who changed which admin account, and how. Never holds a password. */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'admin_id', 'action', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }

    public static function record(?int $actorId, Admin $admin, string $action, ?array $changes = null): void
    {
        static::query()->create([
            'actor_id' => $actorId,
            'admin_id' => $admin->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
