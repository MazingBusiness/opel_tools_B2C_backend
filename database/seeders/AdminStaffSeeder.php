<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Creates the super admin from .env once. If a super admin already exists it does
 * nothing, so re-running seeders or deploys never overwrites a changed password.
 * Lost password: php artisan admin:reset-super-password
 */
class AdminStaffSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL');
        $password = env('ADMIN_SEED_PASSWORD');
        $name = env('ADMIN_SEED_NAME', 'Admin');

        if (Admin::query()->where('is_super', true)->exists()) {
            return;
        }

        if (! filled($email) || ! filled($password)) {
            $this->command?->warn('AdminStaffSeeder skipped: set ADMIN_SEED_EMAIL and ADMIN_SEED_PASSWORD.');

            return;
        }

        $admin = new Admin;
        $admin->forceFill([
            'name' => filled($name) ? (string) $name : 'Admin',
            'email' => strtolower(trim((string) $email)),
            'password' => (string) $password,
            'is_super' => true,
        ])->save();
    }
}
