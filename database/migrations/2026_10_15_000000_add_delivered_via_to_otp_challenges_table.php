<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the code was handed off: email | whatsapp | sms | log (local, no credentials). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->string('delivered_via', 16)->nullable()->after('identifier');
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropColumn('delivered_via');
        });
    }
};
