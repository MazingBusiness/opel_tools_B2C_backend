<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic-concurrency counter for admin SEO saves. Every accepted save
 * increments it; the admin form sends the version it loaded (0/null = "no row
 * yet") and gets 409 on mismatch. Existing rows start at 1 so "0" always means
 * "no row".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('target_key');
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
