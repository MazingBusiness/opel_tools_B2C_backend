<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('gst_rate', 5, 2)->nullable()->after('unit_price');
            $table->string('hsn')->nullable()->after('gst_rate');
        });
        Schema::table('challan_items', function (Blueprint $table): void {
            $table->decimal('gst_rate', 5, 2)->after('unit_price');
            $table->string('hsn')->nullable()->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('challan_items', function (Blueprint $table): void {
            $table->dropColumn(['gst_rate', 'hsn']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['gst_rate', 'hsn']);
        });
    }
};
