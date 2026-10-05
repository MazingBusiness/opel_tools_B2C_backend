<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;

class AdminStaffSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL');
        $password = env('ADMIN_SEED_PASSWORD');
        $name = env('ADMIN_SEED_NAME', 'Admin');

        if (! filled($email) || ! filled($password)) {
            $this->command?->warn('AdminStaffSeeder skipped: set ADMIN_SEED_EMAIL and ADMIN_SEED_PASSWORD.');

            return;
        }

        $email = strtolower(trim((string) $email));

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => filled($name) ? (string) $name : 'Admin',
            'password' => (string) $password,
            'is_staff' => true,
            'email_verified_at' => now(),
        ])->save();
    }
}
