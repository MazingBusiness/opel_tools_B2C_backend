<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('status', 32)->default('pending_payment'); // pending_payment|paid|failed|cancelled
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('shipping_name', 120);
            $table->string('shipping_phone', 20);
            $table->string('shipping_line1');
            $table->string('shipping_line2')->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_state', 100);
            $table->string('shipping_pincode', 6);
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('payment_status', 32)->default('unpaid'); // unpaid|pending|paid|failed
            $table->string('payment_link_id')->nullable()->index();
            $table->text('payment_link_url')->nullable();
            $table->timestamp('payment_link_expires_at')->nullable();
            $table->string('zoho_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('title');
            $table->string('image_url')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('qty');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('zoho_payment_tokens', function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('zoho_payment_tokens');
    }
};
