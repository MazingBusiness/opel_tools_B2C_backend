<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('piece_per_carton');
        });

        // One row per catalog:sync-b2b run. The last complete run's `received`
        // is the baseline the next run compares against before unpublishing.
        Schema::create('catalog_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->index(); // running|succeeded|failed
            $table->unsignedInteger('expected')->default(0);
            $table->unsignedInteger('received')->default(0);
            $table->boolean('complete')->default(false);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('unpublished')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_runs');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('warranty_months');
        });
    }
};
