<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('approved_by');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('branch', 20)->index();
            $table->foreignId('user_id')->constrained('users');
            $table->string('address_key', 64)->index();
            $table->string('billing_name');
            $table->string('billing_phone', 20);
            $table->string('billing_line1');
            $table->string('billing_line2')->nullable();
            $table->string('billing_city');
            $table->string('billing_state');
            $table->string('billing_pincode', 10);
            $table->boolean('intra_state');
            $table->decimal('taxable_total', 12, 2);
            $table->decimal('cgst_total', 12, 2);
            $table->decimal('sgst_total', 12, 2);
            $table->decimal('igst_total', 12, 2);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('challans', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('sub_order_id')->constrained('sub_orders');
            $table->string('branch', 20)->index();
            $table->string('address_key', 64)->index();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('challan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challan_id')->constrained('challans')->cascadeOnDelete();
            $table->foreignId('sub_order_item_id')->constrained('sub_order_items');
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->string('part_no');
            $table->string('title');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('qty');
            $table->timestamps();
        });

        // One B2B stock-deduction push per challan line. Status: pending | sent | failed | needs_check.
        Schema::create('b2b_pushes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challan_item_id')->unique()->constrained('challan_items')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('b2c_order_record_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('challan_item_id')->unique()->constrained('challan_items');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('title');
            $table->string('part_no');
            $table->string('hsn')->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('taxable', 12, 2);
            $table->decimal('cgst', 12, 2);
            $table->decimal('sgst', 12, 2);
            $table->decimal('igst', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('b2b_pushes');
        Schema::dropIfExists('challan_items');
        Schema::dropIfExists('challans');
        Schema::dropIfExists('invoices');
        Schema::table('sub_orders', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
