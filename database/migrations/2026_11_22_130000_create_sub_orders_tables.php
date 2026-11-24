<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // null = awaiting approval (once processing); partial | approved | cancelled.
            $table->string('approval_status', 20)->nullable()->after('status')->index();
            $table->unsignedInteger('allocation_version')->default(0)->after('approval_status');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('qty_cancelled')->default(0)->after('qty');
        });

        Schema::create('document_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();
        });

        Schema::create('sub_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('branch', 20)->index();
            $table->string('number', 40)->unique();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sub_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_order_id')->constrained('sub_orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->string('part_no');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('qty_challaned')->default(0);
            $table->unsignedInteger('qty_cancelled')->default(0);
            $table->timestamps();

            $table->index('part_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_order_items');
        Schema::dropIfExists('sub_orders');
        Schema::dropIfExists('document_counters');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('qty_cancelled');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'allocation_version']);
        });
    }
};
