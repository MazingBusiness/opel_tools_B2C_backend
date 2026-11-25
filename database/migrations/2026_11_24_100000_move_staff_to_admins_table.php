<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff move out of `users` into their own `admins` table. Existing staff keep
 * their password hash and become super admins (they had full access before).
 * Every column that stored a staff users.id is repointed to the new admins.id.
 * Staff logins are revoked, so admins sign in again after deploy.
 */
return new class extends Migration
{
    /** Columns that hold the acting admin's id. */
    private const ADMIN_ID_COLUMNS = [
        ['category_group_overrides', 'updated_by'],
        ['category_overrides', 'updated_by'],
        ['product_overrides', 'updated_by'],
        ['product_variant_overrides', 'updated_by'],
        ['home_layouts', 'updated_by'],
        ['sub_orders', 'approved_by'],
        ['challans', 'created_by'],
        ['invoices', 'created_by'],
    ];

    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->boolean('is_super')->default(false);
            $table->json('modules')->nullable();
            $table->json('branches')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('action', 40);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('home_layouts', function (Blueprint $table): void {
            $table->dropForeign(['updated_by']);
        });

        DB::transaction(function (): void {
            $staff = DB::table('users')->where('is_staff', true)->orderBy('id')->get();
            $map = [];
            foreach ($staff as $u) {
                $map[(int) $u->id] = DB::table('admins')->insertGetId([
                    'name' => filled($u->name) ? $u->name : 'Admin',
                    'email' => strtolower(trim((string) $u->email)),
                    'phone' => $u->phone,
                    'password' => (string) $u->password,
                    'is_super' => true,
                    'disabled_at' => $u->disabled_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (self::ADMIN_ID_COLUMNS as [$table, $column]) {
                // Ids that were not staff (should not exist) are cleared rather than left pointing at a shopper.
                DB::table($table)->whereNotNull($column)->whereNotIn($column, array_keys($map) ?: [0])->update([$column => null]);
                if ($map !== []) {
                    // One CASE update per column, so an old id can never collide with a new one mid-way.
                    $case = 'CASE '.$column.' '.implode(' ', array_map(
                        fn (int $old, int $new) => "WHEN {$old} THEN {$new}",
                        array_keys($map),
                        $map,
                    )).' END';
                    DB::table($table)->whereIn($column, array_keys($map))->update([$column => DB::raw($case)]);
                }
            }

            $ids = array_keys($map);
            if ($ids !== []) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', 'App\\Modules\\Auth\\Models\\User')
                    ->whereIn('tokenable_id', $ids)
                    ->delete();
                DB::table('password_reset_tokens')->delete();

                // Orders cascade-delete with their user, so staff rows that ever ordered stay as disabled shoppers.
                $kept = DB::table('orders')->whereIn('user_id', $ids)->distinct()->pluck('user_id')
                    ->merge(DB::table('invoices')->whereIn('user_id', $ids)->distinct()->pluck('user_id'))
                    ->map(fn ($id) => (int) $id)->unique()->all();
                DB::table('users')->whereIn('id', $kept)->whereNull('disabled_at')->update(['disabled_at' => now()]);
                DB::table('users')->whereIn('id', array_diff($ids, $kept))->delete();
            }
        });

        Schema::table('home_layouts', function (Blueprint $table): void {
            $table->foreign('updated_by')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_staff');
        });
    }

    public function down(): void
    {
        // Not data-reversible: restore from backup to get staff back into users.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_staff')->default(false)->after('password');
        });
        Schema::table('home_layouts', function (Blueprint $table): void {
            $table->dropForeign(['updated_by']);
        });
        DB::table('home_layouts')->update(['updated_by' => null]);
        Schema::table('home_layouts', function (Blueprint $table): void {
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('admins');
    }
};
