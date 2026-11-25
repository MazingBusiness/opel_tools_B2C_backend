<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\Admin;
use App\Modules\Admin\Models\AdminAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Server-side recovery for a lost super admin password. Prompts without echo; never prints it. */
class ResetSuperAdminPasswordCommand extends Command
{
    protected $signature = 'admin:reset-super-password {email? : Super admin email (defaults to ADMIN_SEED_EMAIL)}';

    protected $description = 'Set a new password for the super admin and log out its sessions';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?? env('ADMIN_SEED_EMAIL'))));
        $admin = Admin::query()->where('is_super', true)->where('email', $email)->first();
        if (! $admin instanceof Admin) {
            $this->error('No super admin with that email.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirm = (string) $this->secret('Repeat new password');
        $v = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirm],
            ['password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]],
        );
        if ($v->fails()) {
            $this->error($v->errors()->first('password'));

            return self::FAILURE;
        }

        $admin->forceFill(['password' => $password, 'must_change_password' => false, 'disabled_at' => null])->save();
        $admin->revokeTokens();
        AdminAuditLog::record(null, $admin, 'password_reset', ['via' => 'console']);

        $this->info('Password updated. All sessions for this admin were logged out.');

        return self::SUCCESS;
    }
}
