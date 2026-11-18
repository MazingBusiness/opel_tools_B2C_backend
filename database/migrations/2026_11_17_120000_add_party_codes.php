<?php

use App\Modules\Auth\Support\PartyCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('party_code', 13)->nullable()->unique();
            $table->unsignedTinyInteger('next_address_suffix')->default(PartyCode::FIRST_SUFFIX);
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('party_code', 15)->nullable()->unique();
            $table->string('zoho_contact_id', 32)->nullable();
            $table->timestamp('zoho_synced_at')->nullable();
        });

        // Backfill: every existing user gets a master code; their addresses get suffixes in creation order.
        DB::table('users')->orderBy('id')->select('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                $master = PartyCode::master((int) $user->id);
                $suffix = PartyCode::FIRST_SUFFIX;
                $addressIds = DB::table('addresses')->where('user_id', $user->id)->orderBy('id')->pluck('id');
                foreach ($addressIds as $addressId) {
                    if ($suffix > PartyCode::LAST_SUFFIX) {
                        throw new RuntimeException("User {$user->id} has more than 89 addresses; party codes cannot be assigned.");
                    }
                    DB::table('addresses')->where('id', $addressId)->update(['party_code' => PartyCode::address($master, $suffix++)]);
                }
                DB::table('users')->where('id', $user->id)->update([
                    'party_code' => $master,
                    'next_address_suffix' => $suffix,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropUnique(['party_code']);
            $table->dropColumn(['party_code', 'zoho_contact_id', 'zoho_synced_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['party_code']);
            $table->dropColumn(['party_code', 'next_address_suffix']);
        });
    }
};
